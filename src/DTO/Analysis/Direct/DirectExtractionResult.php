<?php

declare(strict_types=1);

namespace App\DTO\Analysis\Direct;

use App\DTO\Analysis\ExtractedAnswer;
use App\DTO\Analysis\ExtractedAnswerSheetMetadata;

readonly class DirectExtractionResult
{
    public function __construct(
        public ExtractedAnswerSheetMetadata $metadata,
        public array $extractedAnswers,
        public array $extractedQuestions,
        public float $confidence,
        public string $extractionMethod,
        public ?array $ocrMetrics = null,
        public ?array $documentEvidence = null,
        public ?string $language = 'en',
        public ?CurrentExamAnalysis $analysis = null,
        public ?array $questionAnalysis = null,
    ) {
    }
}