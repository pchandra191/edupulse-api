<?php
declare(strict_types=1);

namespace App\DTO\ExaminationModule;

readonly class QuestionPaperSummary
{
    public function __construct(
        public ?string $id,
        public ?string $title,
        public ?string $subjectId,
        public ?string $subjectName,
        public ?int $totalQuestions,
        public ?int $totalMarks,
        public ?string $status,
        public ?string $createdByName,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
