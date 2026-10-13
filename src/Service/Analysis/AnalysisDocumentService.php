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
use App\Exception\Analysis\AnalysisPageLimitExceededException;
use App\Message\Analysis\ProcessAnalysisMessage;
use App\Repository\AnalysisRepository;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use App\Service\Analysis\Processing\PdfPageRendererInterface;
use App\Service\Analysis\Settings\AnalysisSettings;
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
        'image/webp',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AnalysisRepository $pgAnalysisRepository,
        private MongoAnalysisRepository $mongoAnalysisRepository,
        private LoggerInterface $logger,
        private MessageBusInterface $messageBus,
        private AnalysisStorageResolver $storageResolver,
        private AnalysisWorkflowService $analysisWorkflowService,
        private PdfPageRendererInterface $pdfRenderer,
        private AnalysisSettings $analysisSettings,
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

        // Validate all files
        foreach ($request->files as $file) {
            $this->validateFile($file);
        }

        // Check page limit BEFORE storing any files
        $this->checkPageLimit($mongoAnalysis, $request->files, $request->documentType);

        // Store all files
        $fileInfos = [];
        foreach ($request->files as $index => $file) {
            $fileInfo = $this->storeFile($file, $analysisId);
            $fileInfo['page'] = $index + 1; // 1-based page ordering
            $fileInfos[] = $fileInfo;
        }

        // Update MongoDB document
        $documents = $mongoAnalysis->getDocuments();

        if ($request->documentType === 'QUESTION_PAPER') {
            // Question paper remains single file (replace if exists).
            // Metadata comes from $fileInfo (captured before the move) —
            // the UploadedFile's PHP temp path is gone after move().
            $questionPaper = $documents->getQuestionPaper();
            $questionPaper->setFileId($fileInfos[0]['fileId']);
            $questionPaper->setStoredFilename($fileInfos[0]['storedFilename']);
            $questionPaper->setOriginalFilename($fileInfos[0]['originalFilename']);
            $questionPaper->setMimeType($fileInfos[0]['mimeType']);
            $questionPaper->setFileSize($fileInfos[0]['size']);
            $questionPaper->setPageCount($fileInfos[0]['pageCount'] ?? 1);
            $questionPaper->setStatus('UPLOADED');
        } elseif ($request->documentType === 'ANSWER_SHEET') {
            // Answer sheets: append to existing answer sheets.
            // Metadata comes from $fileInfo (captured before the move) —
            // the UploadedFile's PHP temp path is gone after move().
            foreach ($fileInfos as $fileInfo) {
                $answerSheet = new DocumentFile();
                $answerSheet->setFileId($fileInfo['fileId']);
                $answerSheet->setStoredFilename($fileInfo['storedFilename']);
                $answerSheet->setOriginalFilename($fileInfo['originalFilename']);
                $answerSheet->setMimeType($fileInfo['mimeType']);
                $answerSheet->setFileSize($fileInfo['size']);
                $answerSheet->setStatus('UPLOADED');
                $answerSheet->setPage($fileInfo['page']);
                $answerSheet->setPageCount($fileInfo['pageCount'] ?? 1);
                $documents->addAnswerSheet($answerSheet);
            }
        }

        // Preparation lifecycle: mark the analysis as queued for background
        // extraction. Uses the existing processing stage/status architecture.
        if ($request->documentType === 'ANSWER_SHEET') {
            $processing = $mongoAnalysis->getProcessing();
            $processing->setStage('UPLOADED');
            $processing->setStatus('PENDING');
        }

        $mongoAnalysis->setUpdatedAt(new \DateTimeImmutable());
        $this->mongoAnalysisRepository->save($mongoAnalysis);

        $this->logger->info('Analysis document(s) uploaded successfully', [
            'analysis_id' => $analysisId,
            'student_id' => $studentId,
            'document_type' => $request->documentType,
            'file_count' => count($fileInfos),
            'file_ids' => array_column($fileInfos, 'fileId'),
        ]);

        // Dispatch exactly ONE preparation message per upload action (not per
        // file). The message carries stable identifiers only - never the
        // UploadedFile object or any temporary PHP path.
        if ($request->documentType === 'ANSWER_SHEET') {
            $this->logger->info("Analysis {$analysisId}: dispatching prepare message");

            $this->messageBus->dispatch(ProcessAnalysisMessage::create($analysisId, $studentId));

            $this->logger->info("Analysis {$analysisId}: prepare message dispatched");
        }

        // Return information about all uploaded files
        $uploadedFiles = array_map(function ($fileInfo) {
            return [
                'fileId' => $fileInfo['fileId'],
                'storedFilename' => $fileInfo['storedFilename'],
                'originalFilename' => $fileInfo['originalFilename'] ?? null,
                'page' => $fileInfo['page'],
                'status' => 'UPLOADED',
            ];
        }, $fileInfos);

        return [
            'analysisId' => $analysisId,
            'documentType' => $request->documentType,
            'files' => $uploadedFiles,
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
            throw new \InvalidArgumentException('Invalid file type. Allowed: PDF, JPG, PNG, WEBP.');
        }
    }

    /**
     * Check if the upload would exceed the maximum total pages for the analysis.
     * Throws InvalidArgumentException with detailed error if limit would be exceeded.
     */
    private function checkPageLimit(Analysis $mongoAnalysis, array $uploadedFiles, string $documentType): void
    {
        $existingPages = $this->countExistingPages($mongoAnalysis);
        $newPages = $this->countNewPages($uploadedFiles);
        $totalPages = $existingPages + $newPages;

        $this->logger->info('[ANALYSIS-PAGE-LIMIT]', [
            'analysisId' => $mongoAnalysis->getAnalysisId(),
            'existingPages' => $existingPages,
            'newPages' => $newPages,
            'totalPages' => $totalPages,
            'maxPages' => $this->analysisSettings->getUploadMaxTotalPages(),
            'allowed' => $totalPages <= $this->analysisSettings->getUploadMaxTotalPages(),
        ]);

        if ($totalPages > $this->analysisSettings->getUploadMaxTotalPages()) {
            $this->logger->warning('Analysis page limit exceeded', [
                'analysis_id' => $mongoAnalysis->getAnalysisId(),
                'existing_pages' => $existingPages,
                'requested_pages' => $newPages,
                'total_pages' => $totalPages,
                'max_pages' => $this->analysisSettings->getUploadMaxTotalPages(),
            ]);

            throw new AnalysisPageLimitExceededException(
                'This upload would exceed the maximum of ' . $this->analysisSettings->getUploadMaxTotalPages() . ' pages for one analysis.',
                $this->analysisSettings->getUploadMaxTotalPages(),
                $existingPages,
                $newPages,
                $totalPages
            );
        }
    }

    /**
     * Count total pages from existing documents in the analysis.
     */
    private function countExistingPages(Analysis $mongoAnalysis): int
    {
        $total = 0;
        $documents = $mongoAnalysis->getDocuments();

        // Question paper pages
        $questionPaper = $documents->getQuestionPaper();
        if ($questionPaper->getFileId()) {
            $total += $questionPaper->getPageCount() ?? 1;
        }

        // Answer sheets pages
        foreach ($documents->getAnswerSheets() as $answerSheet) {
            if ($answerSheet->getFileId()) {
                $total += $answerSheet->getPageCount() ?? 1;
            }
        }

        return $total;
    }

    /**
     * Count pages for a single document using stored pageCount.
     */
    private function countPagesForDocument(DocumentFile $document): int
    {
        return $document->getPageCount() ?? 1;
    }

    /**
     * Count pages for new upload files.
     * PDF: use pdfRenderer to get actual page count
     * Image: 1 page
     */
    private function countNewPages(array $uploadedFiles): int
    {
        $total = 0;
        foreach ($uploadedFiles as $file) {
            $mimeType = $file->getMimeType();
            if ($mimeType === 'application/pdf') {
                try {
                    $total += $this->pdfRenderer->getPageCount($file->getPathname());
                } catch (\Throwable) {
                    $total += 1; // Conservative fallback
                }
            } else {
                $total += 1; // Images count as 1 page
            }
        }
        return $total;
    }

    private function storeFile(\Symfony\Component\HttpFoundation\File\UploadedFile $file, string $analysisId): array
    {
        $originalFilename = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $size = $file->getSize();

        // Detect actual file type from content to use correct extension
        $detectedMimeType = $this->detectMimeType($file->getPathname());
        $extension = $this->extensionFromMimeType($detectedMimeType);

        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new \InvalidArgumentException('Invalid file type. Allowed: PDF, JPG, PNG, WEBP.');
        }

        $fileId = Uuid::v7()->toRfc4122();
        $storedFilename = $fileId . '.' . $extension;

        $uploadDir = $this->storageResolver->ensureAnalysisDirectory($analysisId);

        $file->move($uploadDir, $storedFilename);

        $permanentPath = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $storedFilename;
        if (!is_file($permanentPath) || !is_readable($permanentPath)) {
            throw new \RuntimeException('Failed to store uploaded document: ' . $storedFilename);
        }

        // Calculate page count for the stored file using detected MIME type
        $pageCount = $this->calculatePageCount($permanentPath, $detectedMimeType);

        return [
            'fileId' => $fileId,
            'storedFilename' => $storedFilename,
            'originalFilename' => $originalFilename,
            'mimeType' => $detectedMimeType,
            'size' => $size,
            'pageCount' => $pageCount,
        ];
    }

    private function detectMimeType(string $filePath): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        return $mimeType ?: 'application/octet-stream';
    }

    private function extensionFromMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }

    /**
     * Calculate page count for a stored file.
     * PDF: use pdfRenderer to get actual page count
     * Image: 1 page
     */
    private function calculatePageCount(string $filePath, string $mimeType): int
    {
        if ($mimeType === 'application/pdf') {
            try {
                return $this->pdfRenderer->getPageCount($filePath);
            } catch (\Throwable) {
                return 1; // Conservative fallback
            }
        }
        return 1; // Images count as 1 page
    }
}