<?php

declare(strict_types=1);

namespace App\DTO\Analysis;

use Symfony\Component\Validator\Constraints as Assert;

readonly class AnalysisMetadataRequestDTO
{
    public function __construct(
        #[Assert\Uuid]
        public ?string $examSetId = null,

        #[Assert\Uuid]
        public ?string $subjectId = null,

        #[Assert\Length(max: 100)]
        public ?string $subjectName = null,

        #[Assert\PositiveOrZero]
        public ?int $totalMarks = null,

        #[Assert\PositiveOrZero]
        public ?int $scoredMarks = null,

        #[Assert\Length(max: 100)]
        public ?string $studentName = null,

        #[Assert\Length(max: 50)]
        public ?string $rollNumber = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            examSetId: $data['examSetId'] ?? null,
            subjectId: $data['subjectId'] ?? null,
            subjectName: $data['subjectName'] ?? $data['subject'] ?? null,
            totalMarks: isset($data['totalMarks']) ? (int)$data['totalMarks'] : null,
            scoredMarks: isset($data['scoredMarks']) ? (int)$data['scoredMarks'] : null,
            studentName: $data['studentName'] ?? null,
            rollNumber: $data['rollNumber'] ?? null,
        );
    }

    public function hasSubject(): bool
    {
        return $this->subjectId !== null || $this->subjectName !== null;
    }

    public function hasRequiredMetadata(): bool
    {
        return $this->hasSubject() && $this->totalMarks !== null;
    }
}