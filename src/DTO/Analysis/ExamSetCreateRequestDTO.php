<?php

declare(strict_types=1);

namespace App\DTO\Analysis;

use Symfony\Component\Validator\Constraints as Assert;

readonly class ExamSetCreateRequestDTO
{
    public function __construct(
        #[Assert\Uuid]
        public ?string $academicYearId = null,

        #[Assert\Length(max: 50)]
        public ?string $academicYear = null,

        #[Assert\Uuid]
        public ?string $classId = null,

        #[Assert\Length(max: 50)]
        public ?string $academicClass = null,

        #[Assert\Uuid]
        public ?string $sectionId = null,

        #[Assert\Length(max: 50)]
        public ?string $section = null,

        #[Assert\Uuid]
        public ?string $examId = null,

        #[Assert\Length(max: 100)]
        public ?string $examName = null,

        #[Assert\Uuid]
        public ?string $examSubTypeId = null,

        #[Assert\Length(max: 100)]
        public ?string $examSubType = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            academicYearId: $data['academicYearId'] ?? null,
            academicYear: $data['academicYear'] ?? null,
            classId: $data['classId'] ?? null,
            academicClass: $data['academicClass'] ?? null,
            sectionId: $data['sectionId'] ?? null,
            section: $data['section'] ?? null,
            examId: $data['examId'] ?? null,
            examName: $data['examName'] ?? null,
            examSubTypeId: $data['examSubTypeId'] ?? null,
            examSubType: $data['examSubType'] ?? null,
        );
    }
}