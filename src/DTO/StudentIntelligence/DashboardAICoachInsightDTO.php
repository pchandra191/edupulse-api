<?php

declare(strict_types=1);

namespace App\DTO\StudentIntelligence;

readonly class DashboardAICoachInsightDTO
{
    public function __construct(
        public string $type,
        public string $title,
        public string $description,
        public string $subject,
        public ?float $confidence,
        public string $severity,
        /** @var list<string> */
        public array $evidence,
    ) {}
}
