<?php
declare(strict_types=1);

namespace App\Service\Analysis\Processing;

enum ExtractionProvenance: string
{
    case Extracted = 'EXTRACTED';
    case Inferred = 'INFERRED';
    case Missing = 'MISSING';
}

readonly class ProvenanceValue
{
    public function __construct(
        public mixed $value,
        public ExtractionProvenance $status,
        public float $confidence,
    ) {
    }

    public static function extracted(mixed $value, float $confidence = 0.95): self
    {
        return new self($value, ExtractionProvenance::Extracted, $confidence);
    }

    public static function inferred(mixed $value, float $confidence = 0.7): self
    {
        return new self($value, ExtractionProvenance::Inferred, $confidence);
    }

    public static function missing(): self
    {
        return new self(null, ExtractionProvenance::Missing, 0.0);
    }

    public function isAvailable(): bool
    {
        return $this->status !== ExtractionProvenance::Missing;
    }

    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'status' => $this->status->value,
            'confidence' => $this->confidence,
        ];
    }
}