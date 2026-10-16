<?php

declare(strict_types=1);

namespace App\DTO\StudentIntelligence;

readonly class DashboardPerformanceDTO
{
    public function __construct(
        public ?float $overallScore,
        public int $totalAssessments,
        public int $assessmentsWithValidMarks,
        /** @var list<HistoricalPointDTO> */
        public array $historicalTrend,
    ) {}
}
