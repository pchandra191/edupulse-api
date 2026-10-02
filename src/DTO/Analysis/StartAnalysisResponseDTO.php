<?php
declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class StartAnalysisResponseDTO
{
    public function __construct(
        public string $analysisId,
        public string $status,
    ) {
    }
}