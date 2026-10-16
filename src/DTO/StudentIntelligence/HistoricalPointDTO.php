<?php

declare(strict_types=1);

namespace App\DTO\StudentIntelligence;

readonly class HistoricalPointDTO
{
    public function __construct(
        public string $analysisId,
        public string $date,
        public string $subject,
        public ?int $score,
        public ?int $totalMarks,
        public ?float $percentage,
    ) {}
}
