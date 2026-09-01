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
        public string $mode,
        public string $status,
        public ?string $startedAt,
        public ?string $submittedAt,
        public ?string $autoSubmittedAt,
        public string $createdAt,
        public ?string $updatedAt,
        public ?ExamAttemptTimingDTO $timing,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'examSessionId' => $this->examSessionId,
            'examSessionTitle' => $this->examSessionTitle,
            'studentId' => $this->studentId,
            'studentName' => $this->studentName,
            'mode' => $this->mode,
            'status' => $this->status,
            'startedAt' => $this->startedAt,
            'submittedAt' => $this->submittedAt,
            'autoSubmittedAt' => $this->autoSubmittedAt,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'timing' => $this->timing?->toArray(),
        ];
    }
}
