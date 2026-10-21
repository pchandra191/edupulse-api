<?php

declare(strict_types=1);

namespace App\DTO\StudentIntelligence;

readonly class DashboardStudyStreakDTO
{
    public function __construct(
        public int $currentStreak,
        public ?string $lastActivityDate,
        public bool $available,
    ) {}
}
