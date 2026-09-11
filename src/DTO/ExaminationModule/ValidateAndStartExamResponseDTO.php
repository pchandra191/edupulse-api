<?php
declare(strict_types=1);

namespace App\DTO\ExaminationModule;

readonly class ValidateAndStartExamResponseDTO
{
    public function __construct(
        public string $examinationId,
        public bool $success,
        public string $message,
        public int $validatedSubjects,
        public int $totalSubjects,
        public int $sessionsCreated,
        public int $sessionsReused,
        public array $sessions,
        public array $errors,
        public array $subjectResults,
    ) {
    }
}
