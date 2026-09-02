<?php
declare(strict_types=1);

namespace App\DTO\ExamAttemptModule;

readonly class ExamAttemptListItemResponseDTO
{
    public function __construct(
        public string $attemptId,
        public string $studentId,
        public string $studentName,
        public string $mode,
        public string $status,
        public ?string $startedAt,
        public ?string $submittedAt,
        public ?int $timeTakenSeconds,
        public ?int $score,
        public ?int $maxMarks,
        public ?int $percentage,
    ) {
    }
}
