<?php
declare(strict_types=1);

namespace App\DTO\UserProfile;

use App\Enum\EducationLevel;
use App\Enum\EducationResult;
use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateUserEducationRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $institutionName,

        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['SECONDARY', 'HIGHER_SECONDARY', 'DIPLOMA', 'BACHELORS', 'MASTERS', 'DOCTORATE', 'POST_DOCTORATE', 'CERTIFICATE', 'OTHER'])]
        public string $educationLevel,

        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $qualification,

        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $boardUniversity,

        #[Assert\Length(max: 255)]
        public ?string $specialization = null,

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
            institutionName: $data['institutionName'] ?? '',
            educationLevel: $data['educationLevel'] ?? '',
            qualification: $data['qualification'] ?? '',
            boardUniversity: $data['boardUniversity'] ?? '',
            specialization: self::normalizeString($data['specialization'] ?? null),
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
