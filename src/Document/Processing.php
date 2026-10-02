<?php
declare(strict_types=1);

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/**
 * Processing stages for document analysis:
 * - UPLOADED: Initial upload complete
 * - EXTRACTING_PDF: Extracting text from PDF using SetaPDF
 * - CHECKING_EXTRACTION: Validating extraction quality
 * - OCR_REQUIRED: Digital extraction insufficient, OCR needed
 * - PREPARING_PAGES: Rendering PDF pages to images for OCR
 * - EXTRACTING_HANDWRITING: Running Vision OCR on pages
 * - QUESTION_EXTRACTION: Extracting questions from question paper
 * - ANSWER_MAPPING: Mapping answers to questions
 * - ANSWER_EXTRACTION: Extracting answers from answer sheet only
 * - SAVING_METADATA: Persisting extracted metadata
 * - VALIDATING_EXTRACTION: Final validation of extraction
 * - COMPLETED: Processing complete
 * - FAILED: Processing failed
 * - EXTRACTION_FAILED: Both extraction methods failed
 */
#[ODM\EmbeddedDocument]
class Processing
{
    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $stage = null;

    #[ODM\Field(type: 'string')]
    private string $status = 'PENDING';

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $error = null;

    #[ODM\Field(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ODM\Field(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $retryCount = 0;

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $attemptId = 0;

    // OCR metrics
    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $pagesDetected = null;

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $pagesProcessed = null;

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $pagesSkipped = null;

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $ocrPagesProcessed = null;

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $ocrCacheHits = null;

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $ocrAttempts = null;

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $ocrRetryCount = null;

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $pagesAttempted = null;

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $pagesFailed = null;

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $stoppedReason = null;

    public function getStage(): ?string
    {
        return $this->stage;
    }

    public function setStage(?string $stage): self
    {
        $this->stage = $stage;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): self
    {
        $this->error = $error;
        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getRetryCount(): ?int
    {
        return $this->retryCount;
    }

    public function setRetryCount(?int $retryCount): self
    {
        $this->retryCount = $retryCount;
        return $this;
    }

    public function incrementRetryCount(): self
    {
        $this->retryCount = ($this->retryCount ?? 0) + 1;
        return $this;
    }

    public function getAttemptId(): ?int
    {
        return $this->attemptId;
    }

    public function setAttemptId(?int $attemptId): self
    {
        $this->attemptId = $attemptId;
        return $this;
    }

    // OCR metrics getters and setters
    public function getPagesDetected(): ?int
    {
        return $this->pagesDetected;
    }

    public function setPagesDetected(?int $pagesDetected): self
    {
        $this->pagesDetected = $pagesDetected;
        return $this;
    }

    public function getPagesProcessed(): ?int
    {
        return $this->pagesProcessed;
    }

    public function setPagesProcessed(?int $pagesProcessed): self
    {
        $this->pagesProcessed = $pagesProcessed;
        return $this;
    }

    public function getPagesSkipped(): ?int
    {
        return $this->pagesSkipped;
    }

    public function setPagesSkipped(?int $pagesSkipped): self
    {
        $this->pagesSkipped = $pagesSkipped;
        return $this;
    }

    public function getOcrPagesProcessed(): ?int
    {
        return $this->ocrPagesProcessed;
    }

    public function setOcrPagesProcessed(?int $ocrPagesProcessed): self
    {
        $this->ocrPagesProcessed = $ocrPagesProcessed;
        return $this;
    }

    public function getOcrCacheHits(): ?int
    {
        return $this->ocrCacheHits;
    }

    public function setOcrCacheHits(?int $ocrCacheHits): self
    {
        $this->ocrCacheHits = $ocrCacheHits;
        return $this;
    }

    public function getOcrAttempts(): ?int
    {
        return $this->ocrAttempts;
    }

    public function setOcrAttempts(?int $ocrAttempts): self
    {
        $this->ocrAttempts = $ocrAttempts;
        return $this;
    }

    public function getOcrRetryCount(): ?int
    {
        return $this->ocrRetryCount;
    }

    public function setOcrRetryCount(?int $ocrRetryCount): self
    {
        $this->ocrRetryCount = $ocrRetryCount;
        return $this;
    }

    // Truthful page counter getters and setters
    public function getPagesAttempted(): ?int
    {
        return $this->pagesAttempted;
    }

    public function setPagesAttempted(?int $pagesAttempted): self
    {
        $this->pagesAttempted = $pagesAttempted;
        return $this;
    }

    public function getPagesFailed(): ?int
    {
        return $this->pagesFailed;
    }

    public function setPagesFailed(?int $pagesFailed): self
    {
        $this->pagesFailed = $pagesFailed;
        return $this;
    }

    public function getStoppedReason(): ?string
    {
        return $this->stoppedReason;
    }

    public function setStoppedReason(?string $stoppedReason): self
    {
        $this->stoppedReason = $stoppedReason;
        return $this;
    }
}