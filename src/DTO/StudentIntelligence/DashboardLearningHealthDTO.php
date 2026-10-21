<?php

declare(strict_types=1);

namespace App\DTO\StudentIntelligence;

readonly class DashboardLearningHealthDTO
{
    public function __construct(
        public bool $available,
        public ?float $score,
        public ?string $label,
        public string $formula,
        public array $components,
    ) {}
}
