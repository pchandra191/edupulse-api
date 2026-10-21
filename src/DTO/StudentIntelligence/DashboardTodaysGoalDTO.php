<?php

declare(strict_types=1);

namespace App\DTO\StudentIntelligence;

readonly class DashboardTodaysGoalDTO
{
    public function __construct(
        public string $action,
        public string $topic,
        public string $subject,
        public string $reason,
        public ?int $durationMinutes,
        public string $type,
    ) {}
}
