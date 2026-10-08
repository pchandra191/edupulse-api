<?php

declare(strict_types=1);

namespace App\DTO\Analysis\Report;

readonly class ConceptReportData
{
    public function __construct(
        public string $name,
        public string $status,
        public ?string $explanation = null,
    ) {
    }
}
