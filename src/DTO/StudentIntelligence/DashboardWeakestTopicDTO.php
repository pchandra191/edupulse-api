<?php

declare(strict_types=1);

namespace App\DTO\StudentIntelligence;

readonly class DashboardWeakestTopicDTO
{
    public function __construct(
        public string $topic,
        public string $subject,
        public int $evidenceCount,
        public bool $recurring,
        public ?string $latestStatus,
        /** @var list<string> */
        public array $supportingAnalyses,
    ) {}
}
