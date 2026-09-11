<?php
declare(strict_types=1);

namespace App\DTO\ExaminationModule;

readonly class StartExamSubjectResponseDTO
{
    public function __construct(
        public string $examSubjectId,
        public bool $success,
        public string $message,
        public ?ExamSessionSummary $examSession,
        public array $errors,
    ) {
    }
}
