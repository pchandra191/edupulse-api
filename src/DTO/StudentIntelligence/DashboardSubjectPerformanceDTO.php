<?php

declare(strict_types=1);

namespace App\DTO\StudentIntelligence;

readonly class DashboardSubjectPerformanceDTO
{
    public function __construct(
        public string $subject,
        public int $exams,
        public ?float $average,
        public ?float $latest,
        public ?float $highest,
        public ?float $lowest,
        public string $trend,
        public int $totalQuestions,
        public int $correct,
        public int $incorrect,
        public array $conceptSummary,
    ) {}
}
