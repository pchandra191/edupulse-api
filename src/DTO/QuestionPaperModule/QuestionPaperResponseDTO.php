<?php
declare(strict_types=1);

namespace App\DTO\QuestionPaperModule;

readonly class QuestionPaperResponseDTO
{
    public function __construct(
        public string $id,
        public string $instituteId,
        public string $instituteName,
        public string $academicYearId,
        public string $academicYearName,
        public string $subjectId,
        public string $subjectName,
        public array $classIds,
        public array $classNames,
        public array $sectionIds,
        public array $sectionNames,
        public ?string $examinationId,
        public ?string $examinationName,
        public string $title,
        public string $date,
        public int $duration,
        public int $maximumMarks,
        public ?string $instructions,
        public string $status,
        public ?string $createdBy,
        public int $questionsCount,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
