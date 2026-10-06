<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
use Symfony\Component\Uid\Uuid;

#[ODM\Document(collection: 'analyses')]
#[ODM\Index(keys: ['analysisId' => 'asc'], unique: true)]
#[ODM\Index(keys: ['studentId' => 'asc'])]
#[ODM\Index(keys: ['status' => 'asc'])]
#[ODM\Index(keys: ['createdAt' => 'desc'])]
class Analysis
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    private string $id;

    #[ODM\Field(type: 'string')]
    private string $analysisId;

    #[ODM\Field(type: 'string')]
    private string $studentId;

    #[ODM\Field(type: 'string')]
    private string $source;

    #[ODM\Field(type: 'string')]
    private string $status;

    #[ODM\EmbedOne(targetDocument: Documents::class)]
    private Documents $documents;

    #[ODM\EmbedOne(targetDocument: Exam::class)]
    private Exam $exam;

    #[ODM\EmbedOne(targetDocument: Processing::class)]
    private Processing $processing;

    #[ODM\Field(type: 'raw', nullable: true)]
    private ?array $aiAnalysis = null;

    #[ODM\Field(type: 'date_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ODM\Field(type: 'date_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $answerSheetFileHash = null;

    #[ODM\Field(type: 'raw', nullable: true)]
    private ?array $documentClassification = null;

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $metadataConfirmationStatus = null;

    #[ODM\Field(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $metadataConfirmedAt = null;

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $metadataConfirmedBy = null;

    public function __construct()
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->analysisId = Uuid::v7()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->documents = new Documents();
        $this->exam = new Exam();
        $this->processing = new Processing();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAnalysisId(): string
    {
        return $this->analysisId;
    }

    public function setAnalysisId(string $analysisId): self
    {
        $this->analysisId = $analysisId;
        return $this;
    }

    public function getStudentId(): string
    {
        return $this->studentId;
    }

    public function setStudentId(string $studentId): self
    {
        $this->studentId = $studentId;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getDocuments(): Documents
    {
        return $this->documents;
    }

    public function setDocuments(Documents $documents): self
    {
        $this->documents = $documents;
        return $this;
    }

    public function getExam(): Exam
    {
        return $this->exam;
    }

    public function setExam(Exam $exam): self
    {
        $this->exam = $exam;
        return $this;
    }

    public function getProcessing(): Processing
    {
        return $this->processing;
    }

    public function setProcessing(Processing $processing): self
    {
        $this->processing = $processing;
        return $this;
    }

    public function getAiAnalysis(): ?array
    {
        return $this->aiAnalysis;
    }

    public function setAiAnalysis(?array $aiAnalysis): self
    {
        $this->aiAnalysis = $aiAnalysis;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getAnswerSheetFileHash(): ?string
    {
        return $this->answerSheetFileHash;
    }

    public function setAnswerSheetFileHash(?string $fileHash): self
    {
        $this->answerSheetFileHash = $fileHash;
        return $this;
    }

    public function getDocumentClassification(): ?array
    {
        return $this->documentClassification;
    }

    public function setDocumentClassification(?array $documentClassification): self
    {
        $this->documentClassification = $documentClassification;
        return $this;
    }

    public function getMetadataConfirmationStatus(): ?string
    {
        return $this->metadataConfirmationStatus;
    }

    public function setMetadataConfirmationStatus(?string $metadataConfirmationStatus): self
    {
        $this->metadataConfirmationStatus = $metadataConfirmationStatus;
        return $this;
    }

    public function getMetadataConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->metadataConfirmedAt;
    }

    public function setMetadataConfirmedAt(?\DateTimeImmutable $metadataConfirmedAt): self
    {
        $this->metadataConfirmedAt = $metadataConfirmedAt;
        return $this;
    }

    public function getMetadataConfirmedBy(): ?string
    {
        return $this->metadataConfirmedBy;
    }

    public function setMetadataConfirmedBy(?string $metadataConfirmedBy): self
    {
        $this->metadataConfirmedBy = $metadataConfirmedBy;
        return $this;
    }
}