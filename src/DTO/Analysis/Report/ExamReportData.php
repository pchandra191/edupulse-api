<?php

declare(strict_types=1);

namespace App\DTO\Analysis\Report;

readonly class ExamReportData
{
    public function __construct(
        public ?string $title = null,
        public ?string $subject = null,
        public ?int $totalMarks = null,
        public ?string $date = null,
        public ?array $extractedMetadata = null,
    ) {
    }
}
