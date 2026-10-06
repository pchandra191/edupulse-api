<?php

declare(strict_types=1);

namespace App\DTO\Analysis\Direct;

readonly class DirectAiAnalysisResult
{
    public function __construct(
        public ?DirectExtractionResult $extraction = null,
        public ?CurrentExamAnalysis $currentExamAnalysis = null,
        public ?string $provider = null,
        public ?string $model = null,
        public float $confidence = 0.0,
        public ?array $errors = null,
    ) {
    }
}