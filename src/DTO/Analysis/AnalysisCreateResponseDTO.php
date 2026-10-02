<?php

declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class AnalysisCreateResponseDTO
{
    public function __construct(
        public string $analysisId,
        public string $source,
        public string $status,
        public string $createdAt,
        public ?string $examSetId = null,
    ) {
    }
}