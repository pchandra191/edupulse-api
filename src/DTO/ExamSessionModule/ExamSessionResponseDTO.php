<?php
declare(strict_types=1);

namespace App\DTO\ExamSessionModule;

readonly class ExamSessionResponseDTO
{
    public function __construct(
        public string $id,
        public string $instituteId,
        public string $instituteName,
        public string $questionPaperId,
        public string $questionPaperTitle,
        public ?string $examinationId,
        public ?string $examinationName,
        public ?string $examSubjectId,
        public ?string $examSubjectName,
        public string $createdById,
        public string $createdByName,
        public string $title,
        public ?string $publishedAt,
        public string $availableFrom,
        public string $availableTo,
        public ?int $durationOverride,
        public bool $shuffleQuestions,
        public string $showResultsMode,
        public string $accessMode,
        public bool $allowReview,
        public int $totalMarks,
        public string $status,
        public string $createdAt,
        public ?string $updatedAt,
        public string $academicYearId,
        public string $academicYearName,
        public string $academicClassId,
        public string $academicClassName,
        public array $sections,
    ) {
    }
}
