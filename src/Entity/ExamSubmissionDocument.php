<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExamSubmissionDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExamSubmissionDocumentRepository::class)]
#[ORM\Table(name: 'exam_submission_documents')]
#[ORM\Index(name: 'idx_exam_submission_document_attempt', fields: ['exam_attempt_id'])]
#[ORM\Index(name: 'idx_exam_submission_document_uploaded_by', fields: ['uploaded_by_id'])]
#[ORM\Index(name: 'idx_exam_submission_document_status', fields: ['processing_status'])]
class ExamSubmissionDocument extends AbstractEntity
{
    #[ORM\ManyToOne(targetEntity: ExamAttempt::class, inversedBy: 'submissionDocuments')]
    #[ORM\JoinColumn(name: 'exam_attempt_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ExamAttempt $examAttempt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $uploadedBy;

    #[ORM\Column(length: 255)]
    private string $originalFilename;

    #[ORM\Column(length: 255)]
    private string $storedFilename;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column(type: Types::INTEGER)]
    private int $fileSize;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $pageCount = null;

    #[ORM\Column(length: 50)]
    private string $documentType = 'ANSWER_SHEET';

    #[ORM\Column(length: 50)]
    private string $processingStatus = 'UPLOADED';

    public function getExamAttempt(): ExamAttempt
    {
        return $this->examAttempt;
    }

    public function setExamAttempt(ExamAttempt $examAttempt): self
    {
        $this->examAttempt = $examAttempt;

        return $this;
    }

    public function getUploadedBy(): User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(User $uploadedBy): self
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): self
    {
        $this->originalFilename = $originalFilename;

        return $this;
    }

    public function getStoredFilename(): string
    {
        return $this->storedFilename;
    }

    public function setStoredFilename(string $storedFilename): self
    {
        $this->storedFilename = $storedFilename;

        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): self
    {
        $this->fileSize = $fileSize;

        return $this;
    }

    public function getPageCount(): ?int
    {
        return $this->pageCount;
    }

    public function setPageCount(?int $pageCount): self
    {
        $this->pageCount = $pageCount;

        return $this;
    }

    public function getDocumentType(): string
    {
        return $this->documentType;
    }

    public function setDocumentType(string $documentType): self
    {
        $this->documentType = $documentType;

        return $this;
    }

    public function getProcessingStatus(): string
    {
        return $this->processingStatus;
    }

    public function setProcessingStatus(string $processingStatus): self
    {
        $this->processingStatus = $processingStatus;

        return $this;
    }
}
