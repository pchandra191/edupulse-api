<?php

declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class ExamSetSummaryDTO
{
    public function __construct(
        public string $id,
        public ?string $academicYear,
        public ?string $academicClass,
        public ?string $section,
        public ?string $examName,
        public ?string $examSubType,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}