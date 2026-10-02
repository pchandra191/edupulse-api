<?php

declare(strict_types=1);

namespace App\DTO\Analysis;

/** @param array<AnalysisSummaryDTO> $analyses */
readonly class ExamSetResponseDTO
{
    public function __construct(
        public string $id,
        public ?string $academicYearId,
        public ?string $academicYear,
        public ?string $classId,
        public ?string $academicClass,
        public ?string $sectionId,
        public ?string $section,
        public ?string $examId,
        public ?string $examName,
        public ?string $examSubTypeId,
        public ?string $examSubType,
        public array $analyses,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}