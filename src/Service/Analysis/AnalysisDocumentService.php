<?php

declare(strict_types=1);

namespace App\Service\Analysis;

use App\Enum\Analysis\AnalysisDocumentConstraints;
use App\Document\Analysis;
use App\Document\DocumentFile;
use App\DTO\Analysis\UploadAnalysisDocumentRequestDTO;
use App\Entity\User;
use App\Enum\AnalysisStatus;
use App\Exception\AnalysisNotFoundException;
use App\Message\Analysis\ProcessAnalysisMessage;
use App\Repository\AnalysisRepository;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

readonly class AnalysisDocumentService
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AnalysisRepository $pgAnalysisRepository,
        private MongoAnalysisRepository $mongoAnalysisRepository,
        private LoggerInterface $logger,
        private MessageBusInterface $messageBus,
        private AnalysisStorageResolver $storageResolver,
        private AnalysisWorkflowService $analysisWorkflowService,
    ) {
    }

    public function uploadDocument(User $student, string $analysisId, UploadAnalysisDocumentRequestDTO $request): array
    {
        $studentId = (string) $student->getId();

        // Verify ownership via PostgreSQL
        $pgAnalysis = $this->pgAnalysisRepository->findByAnalysisIdAndStudent($analysisId, $student);
        if (!$pgAnalysis instanceof \App\Entity\Analysis) {
            $this->logger->warning('Analysis not found or access denied for upload', [
                'analysis_id' => $analysisId,
                'student_id' => $studentId,
            ]);
            throw new AnalysisNotFoundException('Analysis not found.');
        }

        // Get the MongoDB document
        $mongoAnalysis = $this->mongoAnalysisRepository->findByAnalysisIdAndStudentId($analysisId, $studentId);
        if (!$mongoAnalysis instanceof Analysis) {
            $this->logger->error('MongoDB analysis document not found', [
                'analysis_id' => $analysisId,
                'student_id' => $studentId,
            ]);
            throw new AnalysisNotFoundException('Analysis not found.');
        }

        // Validate file
        $this->validateFile($request->file);

        // Store the file
        $fileInfo = $this->storeFile($request->file, $analysisId);
        $fileId = $fileInfo['fileId'];

        // Update MongoDB document
        $documents = $mongoAnalysis->getDocuments();

        if ($request->documentType === 'QUESTION_PAPER') {
            $questionPaper = $documents->getQuestionPaper();
            $questionPaper->setFileId($fileId);
            $questionPaper->setStatus('UPLOADED');
        } elseif ($request->documentType === 'ANSWER_SHEET') {
            $answerSheet = $documents->getAnswerSheet();
            $answerSheet->setFileId($fileId);
            $answerSheet->setStatus('UPLOADED');
            
            // Trigger document processing after answer sheet upload
            $this->messageBus->dispatch(ProcessAnalysisMessage::create($analysisId, $studentId));
            
            // No auto-publish - metadata review required first
        }

        $mongoAnalysis->setUpdatedAt(new \DateTimeImmutable());
        $this->mongoAnalysisRepository->save($mongoAnalysis);

        $this->logger->info('Analysis document uploaded successfully', [
            'analysis_id' => $analysisId,
            'student_id' => $studentId,
            'document_type' => $request->documentType,
            'file_id' => $fileId,
            'stored_filename' => $fileInfo['storedFilename'],
        ]);

        return [
            'analysisId' => $analysisId,
            'documentType' => $request->documentType,
            'fileId' => $fileId,
            'status' => 'UPLOADED',
        ];
    }
    private function validateFile(\Symfony\Component\HttpFoundation\File\UploadedFile $file): void
    {
        if ($file->getSize() > AnalysisDocumentConstraints::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException(
                'File size exceeds the maximum limit of ' . AnalysisDocumentConstraints::MAX_FILE_SIZE_MB . ' MB.'
            );
        }

        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid file type. Allowed: PDF, JPG, PNG.');
        }
    }

private function storeFile(\Symfony\Component\HttpFoundation\File\UploadedFile $file, string $analysisId): array
    {
        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new \InvalidArgumentException('Invalid file extension. Allowed: pdf, jpg, jpeg, png.');
        }

        $fileId = Uuid::v7()->toRfc4122();
        $storedFilename = $fileId . '.' . $extension;

        $uploadDir = $this->storageResolver->ensureAnalysisDirectory($analysisId);

        $file->move($uploadDir, $storedFilename);

        return [
            'fileId' => $fileId,
            'storedFilename' => $storedFilename,
        ];
    }
}