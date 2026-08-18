<?php
declare(strict_types=1);

namespace App\DTO\QuestionPaperModule;

use App\DTO\QuestionPaperModule;

readonly class QuestionPaperPreviewResponseDTO
{
    /**
     * @param list<QuestionResponseDTO> $questions
     */
    public function __construct(
        public string $instituteName,
        public string $title,
        public string $subjectName,
        public string $academicYearName,
        public array $classNames,
        public array $sectionNames,
        public ?string $examinationName,
        public string $date,
        public int $duration,
        public int $maximumMarks,
        public ?string $instructions,
        public string $status,
        public array $questions,
    ) {
    }
}
