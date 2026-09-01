<?php
declare(strict_types=1);

namespace App\DTO\ExamSessionModule;

readonly class StudentParticipationSummaryDTO
{
    public function __construct(
        public int $eligible,
        public int $attempted,
        public int $inProgress,
        public int $submitted,
        public int $autoSubmitted,
        public int $notAttempted,
    ) {
    }

    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'attempted' => $this->attempted,
            'inProgress' => $this->inProgress,
            'submitted' => $this->submitted,
            'autoSubmitted' => $this->autoSubmitted,
            'notAttempted' => $this->notAttempted,
        ];
    }
}
