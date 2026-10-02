<?php
declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class AIAnalysisRecommendationDTO
{
    public function __construct(
        public string $action,
        public string $reason,
        public string $priority,
    ) {
    }
}