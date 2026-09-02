<?php
declare(strict_types=1);

namespace App\Service\ExamSubmissionDocumentModule;

use App\DTO\ExamSubmissionDocumentModule\ExamSubmissionDocumentResponseDTO;
use App\DTO\ExamSubmissionDocumentModule\UploadExamDocumentRequestDTO;
use App\Entity\ExamAttempt;
use App\Entity\ExamSession;
use App\Entity\ExamSubmissionDocument;
use App\Entity\User;
use App\Enum\ExamAttemptStatus;
use App\Exception\AuthenticationException;
use App\Repository\ExamAttemptRepository;
use App\Repository\ExamSubmissionDocumentRepository;
use DateTimeInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\Registry as WorkflowRegistry;

readonly class ExamSubmissionDocumentService
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
    private const MAX_DOCUMENTS_PER_ATTEMPT = 3;

    public function __construct(
        private Security $security,
        private ExamAttemptRepository $examAttemptRepository,
        private ExamSubmissionDocumentRepository $examSubmissionDocumentRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private WorkflowRegistry $workflowRegistry,
        private string $projectDir,
    ) {
    }

    public function uploadDocument(Uuid $attemptId, UploadExamDocumentRequestDTO $request): ExamSubmissionDocumentResponseDTO
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('User not authenticated.');
        }

        $attempt = $this->examAttemptRepository->find($attemptId);

        if (!$attempt instanceof ExamAttempt) {
            throw new AuthenticationException('Exam attempt not found.');
        }

        $examSession = $attempt->getExamSession();

        if (!$this->isAuthorized($user, $attempt)) {
            throw new AuthenticationException('You are not authorized to upload documents for this attempt.');
        }

        if (!$this->isUploadAllowed($attempt)) {
            throw new \App\Common\Exception\BusinessException('Document upload is not allowed for this attempt state.');
        }

        $this->entityManager->beginTransaction();

        try {
            $lockedAttempt = $this->entityManager->find(
                ExamAttempt::class,
                $attemptId,
                LockMode::PESSIMISTIC_WRITE
            );

            $existingCount = $this->examSubmissionDocumentRepository->createQueryBuilder('d')
                ->select('COUNT(d.id)')
                ->where('d.examAttempt = :attempt')
                ->setParameter('attempt', $lockedAttempt)
                ->getQuery()
                ->getSingleScalarResult();

            if ((int) $existingCount >= self::MAX_DOCUMENTS_PER_ATTEMPT) {
                throw new \App\Common\Exception\BusinessException(
                    sprintf('Maximum %d documents allowed per attempt.', self::MAX_DOCUMENTS_PER_ATTEMPT)
                );
            }

            $file = $request->file;
            $uploadedFile = $file;

            if ($uploadedFile->getSize() > self::MAX_FILE_SIZE) {
                throw new \InvalidArgumentException('File size exceeds the maximum limit of 10 MB.');
            }

            $mimeType = $uploadedFile->getMimeType();

            if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
                throw new \InvalidArgumentException('Invalid file type. Allowed: PDF, JPG, PNG, WEBP.');
            }

            $storedFilename = Uuid::v7()->toRfc4122() . '.' . $uploadedFile->getClientOriginalExtension();

            $uploadDir = $this->getUploadDirectory();
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                throw new \RuntimeException(sprintf('Unable to create upload directory: %s', $uploadDir));
            }

            $uploadedFile->move($uploadDir, $storedFilename);

            $pageCount = null;

            if ($mimeType === 'application/pdf') {
                $pageCount = $this->estimatePdfPageCount($uploadDir . '/' . $storedFilename);
            }

            $document = new ExamSubmissionDocument();
            $document->setExamAttempt($lockedAttempt);
            $document->setUploadedBy($user);
            $document->setOriginalFilename($uploadedFile->getClientOriginalName());
            $document->setStoredFilename($storedFilename);
            $document->setMimeType($mimeType);
            $document->setFileSize($uploadedFile->getSize());
            $document->setPageCount($pageCount);
            $document->setDocumentType($request->documentType ?? 'ANSWER_SHEET');
            $document->setProcessingStatus('UPLOADED');

            $this->entityManager->persist($document);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($document);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to upload exam submission document.', [
                'attempt_id' => (string) $attemptId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @return array<ExamSubmissionDocumentResponseDTO>
     */
    public function getDocuments(Uuid $attemptId): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('User not authenticated.');
        }

        $attempt = $this->examAttemptRepository->find($attemptId);

        if (!$attempt instanceof ExamAttempt) {
            throw new AuthenticationException('Exam attempt not found.');
        }

        if (!$this->isAuthorized($user, $attempt)) {
            throw new AuthenticationException('You are not authorized to view documents for this attempt.');
        }

        $documents = $this->examSubmissionDocumentRepository->findByAttempt($attempt);

        return array_map(
            fn(ExamSubmissionDocument $document) => $this->mapToResponse($document),
            $documents
        );
    }

    public function deleteDocument(Uuid $attemptId, Uuid $documentId): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('User not authenticated.');
        }

        $attempt = $this->examAttemptRepository->find($attemptId);

        if (!$attempt instanceof ExamAttempt) {
            throw new AuthenticationException('Exam attempt not found.');
        }

        if (!$this->isAuthorized($user, $attempt)) {
            throw new AuthenticationException('You are not authorized to delete documents for this attempt.');
        }

        $document = $this->examSubmissionDocumentRepository->findByIdAndAttempt($documentId, $attemptId);

        if (!$document instanceof ExamSubmissionDocument) {
            throw new AuthenticationException('Document not found.');
        }

        $this->entityManager->beginTransaction();

        try {
            $lockedAttempt = $this->entityManager->find(
                ExamAttempt::class,
                $attemptId,
                LockMode::PESSIMISTIC_WRITE
            );

            $this->entityManager->remove($document);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to delete exam submission document.', [
                'attempt_id' => (string) $attemptId,
                'document_id' => (string) $documentId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function isAuthorized(User $user, ExamAttempt $attempt): bool
    {
        if ($attempt->getStudent()->getId()->equals($user->getId())) {
            return true;
        }

        $userRoles = $user->getRoles();

        if (in_array('ROLE_SUPER_ADMIN', $userRoles, true)) {
            return true;
        }

        $examSession = $attempt->getExamSession();
        $institute = $examSession->getInstitute();

        if (!$institute->getId()->equals($user->getInstitute()?->getId())) {
            return false;
        }

        if (in_array('ROLE_INSTITUTE', $userRoles, true)) {
            return true;
        }

        if (in_array('ROLE_TEACHER', $userRoles, true)) {
            $teacherAssignmentRepo = $this->entityManager->getRepository(\App\Entity\TeacherAssignment::class);

            $student = $attempt->getStudent();
            $studentEnrollmentRepo = $this->entityManager->getRepository(\App\Entity\StudentEnrollment::class);
            $enrollment = $studentEnrollmentRepo->createQueryBuilder('se')
                ->where('se.student = :student')
                ->andWhere('se.academicClass = :academicClass')
                ->andWhere('se.status = :status')
                ->setParameter('student', $student->getId())
                ->setParameter('academicClass', $examSession->getAcademicClass()->getId())
                ->setParameter('status', 'ACTIVE')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if (!$enrollment) {
                return false;
            }

            $assignment = $teacherAssignmentRepo->createQueryBuilder('ta')
                ->where('ta.teacher = :teacher')
                ->andWhere('ta.academicClass = :academicClass')
                ->andWhere('ta.status = :status')
                ->setParameter('teacher', $user->getId())
                ->setParameter('academicClass', $examSession->getAcademicClass()->getId())
                ->setParameter('status', 'ACTIVE')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            return $assignment !== null;
        }

        return false;
    }

    private function isUploadAllowed(ExamAttempt $attempt): bool
    {
        $status = $attempt->getStatus();

        if ($attempt->isOffline()) {
            return match ($status->value) {
                ExamAttemptStatus::NotStarted->value => true,
                ExamAttemptStatus::InProgress->value => true,
                ExamAttemptStatus::Submitted->value => true,
                default => false,
            };
        }

        return match ($status->value) {
            ExamAttemptStatus::InProgress->value => true,
            ExamAttemptStatus::Submitted->value => true,
            ExamAttemptStatus::AutoSubmitted->value => true,
            default => false,
        };
    }

    private function getUploadDirectory(): string
    {
        return rtrim($_ENV['EXAM_DOCUMENT_UPLOAD_DIR'] ?? ($_SERVER['APP_ENV'] === 'test' ? sys_get_temp_dir() : $this->projectDir . '/var/uploads/exam_documents'), '/');
    }

    private function estimatePdfPageCount(string $filePath): ?int
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            return null;
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($filePath);

            return count($pdf->getPages());
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function mapToResponse(ExamSubmissionDocument $document): ExamSubmissionDocumentResponseDTO
    {
        return new ExamSubmissionDocumentResponseDTO(
            id: $document->getId()->toRfc4122(),
            examAttemptId: $document->getExamAttempt()->getId()->toRfc4122(),
            uploadedById: $document->getUploadedBy()->getId()->toRfc4122(),
            uploadedByName: $document->getUploadedBy()->getFirstName() . ' ' . $document->getUploadedBy()->getLastName(),
            originalFilename: $document->getOriginalFilename(),
            mimeType: $document->getMimeType(),
            fileSize: $document->getFileSize(),
            pageCount: $document->getPageCount(),
            documentType: $document->getDocumentType(),
            processingStatus: $document->getProcessingStatus(),
            createdAt: $document->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $document->getUpdatedAt()->format(DateTimeInterface::ATOM),
        );
    }
}
