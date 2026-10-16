<?php

declare(strict_types=1);

namespace App\DTO\StudentIntelligence;

readonly class DashboardRecentTestDTO
{
    public function __construct(
        public ?string $analysisId,
        public ?string $subject,
        public ?string $date,
        public ?int $score,
        public ?int $totalMarks,
        public ?float $percentage,
    ) {}
}
