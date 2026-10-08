<?php

declare(strict_types=1);

namespace App\DTO\Analysis\Report;

readonly class ConceptMasteryReportData
{
    public function __construct(
        public string $concept,
        public string $status,
        public ?float $confidence = null,
    ) {
    }
}
