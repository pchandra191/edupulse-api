<?php
declare(strict_types=1);

namespace App\DTO\ExamSessionModule;

readonly class StudentParticipationAttemptDTO
{
    public function __construct(
        public ?string $id = null,
        public ?string $status = null,
        public ?string $startedAt = null,
        public ?string $submittedAt = null,
        public ?int $timeTakenSeconds = null,
        public ?int $score = null,
        public ?int $percentage = null,
    ) {
    }

    public static function fromExamAttempt(?\App\Entity\ExamAttempt $attempt): self
    {
        if (!$attempt instanceof \App\Entity\ExamAttempt) {
            return new self();
        }

        return new self(
            id: $attempt->getId()->toRfc4122(),
            status: $attempt->getStatus()->value,
            startedAt: $attempt->getStartedAt()?->format(\DateTimeInterface::ATOM),
            submittedAt: $attempt->getSubmittedAt()?->format(\DateTimeInterface::ATOM),
            timeTakenSeconds: $attempt->getTimeTakenSeconds(),
            score: $attempt->getScore(),
            percentage: $attempt->getPercentage(),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'startedAt' => $this->startedAt,
            'submittedAt' => $this->submittedAt,
            'timeTakenSeconds' => $this->timeTakenSeconds,
            'score' => $this->score,
            'percentage' => $this->percentage,
        ];
    }
}
