<?php

declare(strict_types=1);

namespace App\DTO\Student;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UpdateAcademicDetailsRequestDTO
{
    public function __construct(
        #[Assert\Length(max: 255)]
        public ?string $academicYear = null,
        #[Assert\Length(max: 255)]
        public ?string $academicClass = null,
        #[Assert\Length(max: 50)]
        public ?string $section = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            academicYear: self::normalizeString($data['academicYear'] ?? null),
            academicClass: self::normalizeString($data['academicClass'] ?? null),
            section: self::normalizeString($data['section'] ?? null),
        );
    }

    private static function normalizeString(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }
}