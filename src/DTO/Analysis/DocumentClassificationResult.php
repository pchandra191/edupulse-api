<?php
declare(strict_types=1);

namespace App\DTO\Analysis;

use App\Enum\Analysis\DocumentType;

readonly class DocumentClassificationResult
{
    public function __construct(
        public DocumentType $documentType,
        public string $status, // VALID, REJECTED
        public float $confidence, // 0.0 to 1.0
        public string $reason,
        public array $signals,
    ) {
    }

    public function isValid(): bool
    {
        return $this->status === 'VALID' && $this->documentType === DocumentType::AnswerSheet;
    }

    public function isRejected(): bool
    {
        return $this->status === 'REJECTED';
    }

    public function toArray(): array
    {
        return [
            'documentType' => $this->documentType->value,
            'status' => $this->status,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
            'signals' => $this->signals,
        ];
    }

    public static function validAnswerSheet(float $confidence, array $signals): self
    {
        return new self(
            documentType: DocumentType::AnswerSheet,
            status: 'VALID',
            confidence: $confidence,
            reason: 'Valid answer sheet detected',
            signals: $signals,
        );
    }

    public static function questionPaper(float $confidence, array $signals): self
    {
        return new self(
            documentType: DocumentType::QuestionPaper,
            status: 'REJECTED',
            confidence: $confidence,
            reason: 'This looks like a question paper, not a completed answer sheet. Please upload the student\'s answer sheet.',
            signals: $signals,
        );
    }

    public static function unknown(float $confidence, array $signals): self
    {
        return new self(
            documentType: DocumentType::Unknown,
            status: 'REJECTED',
            confidence: $confidence,
            reason: 'This file doesn\'t appear to be a student answer sheet. Please upload the completed answer sheet.',
            signals: $signals,
        );
    }
}