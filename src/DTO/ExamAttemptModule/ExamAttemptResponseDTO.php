<?php
declare(strict_types=1);

namespace App\DTO\ExamAttemptModule;

readonly class ExamAttemptResponseDTO
{
    public function __construct(
        public string $id,
        public string $examSessionId,
        public string $examSessionTitle,
        public ?string $studentId,
        public ?string $studentName,
        public string $status,
        public ?string $startedAt,
        public ?string $submittedAt,
        public ?string $autoSubmittedAt,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
