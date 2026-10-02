<?php
declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class AIAnalysisPatternDTO
{
    public function __construct(
        public string $type,
        public string $category,
        public string $topic,
        public int $occurrences,
        public float $confidence,
    ) {
    }
}