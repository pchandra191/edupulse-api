<?php
declare(strict_types=1);

namespace App\DTO\ExamAttemptSecurityEventModule;

use App\Enum\ExamSecurityEventType;
use Symfony\Component\Validator\Constraints as Assert;

readonly class ExamAttemptSecurityEventRequestDTO
{
    public function __construct(
        #[Assert\NotBlank(message: 'Attempt ID is required.')]
        #[Assert\Uuid(message: 'Invalid attempt ID format.')]
        public string $attemptId,

        #[Assert\NotBlank(message: 'Exam session ID is required.')]
        #[Assert\Uuid(message: 'Invalid exam session ID format.')]
        public string $examSessionId,

        #[Assert\NotBlank(message: 'Count is required.')]
        #[Assert\Type(type: 'integer', message: 'Count must be an integer.')]
        #[Assert\Positive(message: 'Count must be at least 1.')]
        public int $count,

        #[Assert\NotBlank(message: 'Event type is required.')]
        public ExamSecurityEventType $eventType,

        #[Assert\NotBlank(message: 'Occurred at is required.')]
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
