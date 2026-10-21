<?php

declare(strict_types=1);

namespace App\DTO\StudentIntelligence;

readonly class DashboardUpcomingTestDTO
{
    public function __construct(
        public string $examSessionId,
        public string $title,
        public ?string $subject,
        public \DateTimeImmutable $availableFrom,
        public ?int $totalMarks,
    ) {}
}
