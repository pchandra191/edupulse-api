<?php

declare(strict_types=1);

namespace App\DTO\Analysis\Report;

readonly class ImprovementReportData
{
    public function __construct(
        public ?string $whatToLearn = null,
        public ?string $whatToPractice = null,
        public ?string $recommendedAction = null,
    ) {
    }
}
