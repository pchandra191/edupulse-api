<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

#[ODM\EmbeddedDocument]
class Exam
{
    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $title = null;

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $subject = null;

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $totalMarks = null;

    /** @var array<array{questionId: string, questionText: string, type: string, marks: int, options?: array<string>}> */
    #[ODM\Field(type: 'raw')]
    private array $questions = [];

    /** @var array<array{
     *     questionNumber: array{value: string, status: string, confidence: float},
     *     questionText: array{value: string, status: string, confidence: float},
     *     marks: array{value: int|null, status: string, confidence: float},
     *     type: array{value: string, status: string, confidence: float},
     *     source: array{page: int|null, bbox: array|null},
     *     answerText?: array{value: string, status: string, confidence: float},
     *     answerSource?: array{page: int|null, bbox: array|null}
     * }> */
    #[ODM\Field(type: 'raw')]
    private array $extractedQuestions = [];

    /** @var array<array{
     *     questionNumber: string,
     *     answerText: string,
     *     source: array{page: int|null, bbox: array|null}
     * }> */
    #[ODM\Field(type: 'raw')]
    private array $extractedAnswers = [];

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $questionCount = null;

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $answerCount = null;

    #[ODM\Field(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $extractedAt = null;

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $extractionVersion = null;

    #[ODM\Field(type: 'raw', nullable: true)]
    private ?array $extractedMetadata = null;

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $extractionResult = null;

    #[ODM\Field(type: 'float', nullable: true)]
    private ?float $extractionConfidence = null;

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $extractionMethod = null;

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

    public function __construct()
    {
        $this->extractedMetadata = [];
        $this->extractionResult = '';
        $this->extractionConfidence = 0.0;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function getTotalMarks(): ?int
    {
        return $this->totalMarks;
    }

    public function setTotalMarks(?int $totalMarks): self
    {
        $this->totalMarks = $totalMarks;
        return $this;
    }

    /** @return array<array{questionId: string, questionText: string, type: string, marks: int, options?: array<string>}> */
    public function getQuestions(): array
    {
        return $this->questions;
    }

    /** @param array<array{questionId: string, questionText: string, type: string, marks: int, options?: array<string>}> $questions */
    public function setQuestions(array $questions): self
    {
        $this->questions = $questions;
        return $this;
    }

    /** @return array<array{
     *     questionNumber: array{value: string, status: string, confidence: float},
     *     questionText: array{value: string, status: string, confidence: float},
     *     marks: array{value: int|null, status: string, confidence: float},
     *     type: array{value: string, status: string, confidence: float},
     *     source: array{page: int|null, bbox: array|null},
     *     answerText?: array{value: string, status: string, confidence: float},
     *     answerSource?: array{page: int|null, bbox: array|null}
     * }> */
    public function getExtractedQuestions(): array
    {
        return $this->extractedQuestions;
    }

    /** @param array<array{
     *     questionNumber: array{value: string, status: string, confidence: float},
     *     questionText: array{value: string, status: string, confidence: float},
     *     marks: array{value: int|null, status: string, confidence: float},
     *     type: array{value: string, status: string, confidence: float},
     *     source: array{page: int|null, bbox: array|null},
     *     answerText?: array{value: string, status: string, confidence: float},
     *     answerSource?: array{page: int|null, bbox: array|null}
     * }> $extractedQuestions */
    public function setExtractedQuestions(array $extractedQuestions): self
    {
        $this->extractedQuestions = $extractedQuestions;
        return $this;
    }

    /** @return array<array{
     *     questionNumber: string,
     *     answerText: string,
     *     source: array{page: int|null, bbox: array|null}
     * }> */
    public function getExtractedAnswers(): array
    {
        return $this->extractedAnswers;
    }

    /** @param array<array{
     *     questionNumber: string,
     *     answerText: string,
     *     source: array{page: int|null, bbox: array|null}
     * }> $extractedAnswers */
    public function setExtractedAnswers(array $extractedAnswers): self
    {
        $this->extractedAnswers = $extractedAnswers;
        return $this;
    }

    public function getQuestionCount(): ?int
    {
        return $this->questionCount;
    }

    public function setQuestionCount(?int $questionCount): self
    {
        $this->questionCount = $questionCount;
        return $this;
    }

    public function getAnswerCount(): ?int
    {
        return $this->answerCount;
    }

    public function setAnswerCount(?int $answerCount): self
    {
        $this->answerCount = $answerCount;
        return $this;
    }

    public function getExtractedAt(): ?\DateTimeImmutable
    {
        return $this->extractedAt;
    }

    public function setExtractedAt(?\DateTimeImmutable $extractedAt): self
    {
        $this->extractedAt = $extractedAt;
        return $this;
    }

    public function getExtractionVersion(): ?string
    {
        return $this->extractionVersion;
    }

    public function setExtractionVersion(?string $extractionVersion): self
    {
        $this->extractionVersion = $extractionVersion;
        return $this;
    }

    public function getExtractedMetadata(): ?array
    {
        return $this->extractedMetadata;
    }

    public function setExtractedMetadata(?array $extractedMetadata): self
    {
        $this->extractedMetadata = $extractedMetadata;
        return $this;
    }

    public function getExtractionResult(): ?string
    {
        return $this->extractionResult;
    }

    public function setExtractionResult(?string $extractionResult): self
    {
        $this->extractionResult = $extractionResult;
        return $this;
    }

    public function getExtractionConfidence(): ?float
    {
        return $this->extractionConfidence;
    }

    public function setExtractionConfidence(?float $extractionConfidence): self
    {
        $this->extractionConfidence = $extractionConfidence;
        return $this;
    }

    public function getExtractionMethod(): ?string
    {
        return $this->extractionMethod;
    }

    public function setExtractionMethod(?string $extractionMethod): self
    {
        $this->extractionMethod = $extractionMethod;
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

    // Truthful page counters
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