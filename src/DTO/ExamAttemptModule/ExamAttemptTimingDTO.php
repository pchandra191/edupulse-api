<?php
declare(strict_types=1);

namespace App\DTO\ExamAttemptModule;

readonly class ExamAttemptTimingDTO
{
    public function __construct(
        public ?string $startedAt,
        public ?int $allowedDurationSeconds,
        public ?string $expiresAt,
        public ?int $remainingSeconds,
    ) {
    }

    public function toArray(): array
    {
        return [
            'startedAt' => $this->startedAt,
            'allowedDurationSeconds' => $this->allowedDurationSeconds,
            'expiresAt' => $this->expiresAt,
            'remainingSeconds' => $this->remainingSeconds,
        ];
    }
}