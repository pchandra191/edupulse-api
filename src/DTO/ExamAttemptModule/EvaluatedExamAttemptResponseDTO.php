<?php
declare(strict_types=1);

namespace App\DTO\ExamAttemptModule;

readonly class EvaluatedExamAttemptResponseDTO
{
    public function __construct(
        public string $attemptId,
        public StudentReferenceDTO $student,
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
