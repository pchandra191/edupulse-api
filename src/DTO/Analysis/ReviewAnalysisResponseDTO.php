<?php
declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class ReviewAnalysisResponseDTO
{
    public function __construct(
        public string $analysisId,
        public string $status,
        public ExamReviewDTO $exam,
        public int $questionCount,
        public ?MetadataConfirmationDTO $metadataConfirmation = null,
    ) {
    }
}