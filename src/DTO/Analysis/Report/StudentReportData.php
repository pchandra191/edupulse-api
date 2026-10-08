<?php

declare(strict_types=1);

namespace App\DTO\Analysis\Report;

readonly class StudentReportData
{
    public function __construct(
        public ?string $name = null,
        public ?string $rollNumber = null,
        public ?string $class = null,
        public ?string $section = null,
    ) {
    }
}
