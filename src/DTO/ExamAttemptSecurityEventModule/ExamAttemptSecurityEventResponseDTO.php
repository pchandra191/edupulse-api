<?php
declare(strict_types=1);

namespace App\DTO\ExamAttemptSecurityEventModule;

readonly class ExamAttemptSecurityEventResponseDTO
{
    public function __construct(
        public string $id,
        public string $attemptId,
        public string $examSessionId,
        public string $eventType,
        public int $count,
        public string $occurredAt,
        public string $createdAt,
    ) {
    }
}
