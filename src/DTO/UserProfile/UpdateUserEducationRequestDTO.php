<?php
declare(strict_types=1);

namespace App\DTO\UserProfile;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UpdateUserEducationRequestDTO
{
    public function __construct(
        #[Assert\Length(max: 255)]
        public ?string $institutionName = null,

        #[Assert\Choice(choices: ['SECONDARY', 'HIGHER_SECONDARY', 'DIPLOMA', 'BACHELORS', 'MASTERS', 'DOCTORATE', 'POST_DOCTORATE', 'CERTIFICATE', 'OTHER'])]
        public ?string $educationLevel = null,

        #[Assert\Length(max: 255)]
        public ?string $qualification = null,

        #[Assert\Length(max: 255)]
        public ?string $specialization = null,

        #[Assert\Length(max: 255)]
        public ?string $boardUniversity = null,

        public ?string $startDate = null,

        public ?string $endDate = null,

        #[Assert\Choice(choices: ['PASS', 'FAIL', 'PENDING'])]
        public ?string $result = null,

        #[Assert\Range(min: 0, max: 100)]
        public ?float $percentage = null,

        #[Assert\Length(max: 10)]
        public ?string $grade = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            institutionName: self::normalizeString($data['institutionName'] ?? null),
            educationLevel: self::normalizeString($data['educationLevel'] ?? null),
            qualification: self::normalizeString($data['qualification'] ?? null),
            specialization: self::normalizeString($data['specialization'] ?? null),
            boardUniversity: self::normalizeString($data['boardUniversity'] ?? null),
            startDate: $data['startDate'] ?? null,
            endDate: $data['endDate'] ?? null,
            result: self::normalizeString($data['result'] ?? null),
            percentage: isset($data['percentage']) ? (float) $data['percentage'] : null,
            grade: self::normalizeString($data['grade'] ?? null),
        );
    }

    private static function normalizeString(?string $value): ?string
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return $value;
    }
}
