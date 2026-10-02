<?php

declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class AnalysisSummaryDTO
{
    public function __construct(
        public string $analysisId,
        public string $source,
        public string $status,
        public string $title,
        public string $subject,
        public ?string $examSetId,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}