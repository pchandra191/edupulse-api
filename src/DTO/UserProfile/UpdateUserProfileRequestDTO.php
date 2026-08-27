<?php
declare(strict_types=1);

namespace App\DTO\UserProfile;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UpdateUserProfileRequestDTO
{
    public function __construct(
        #[Assert\Length(max: 100)]
        public ?string $firstName = null,

        #[Assert\Length(max: 100)]
        public ?string $middleName = null,

        #[Assert\Length(max: 100)]
        public ?string $lastName = null,

        public ?string $dateOfBirth = null,

        #[Assert\Choice(choices: ['MALE', 'FEMALE', 'OTHER', 'PREFER_NOT_TO_SAY'], message: 'Please select a valid gender.')]
        public ?string $gender = null,

        #[Assert\Choice(choices: ['A_POSITIVE', 'A_NEGATIVE', 'B_POSITIVE', 'B_NEGATIVE', 'AB_POSITIVE', 'AB_NEGATIVE', 'O_POSITIVE', 'O_NEGATIVE'], message: 'Please select a valid blood group.')]
        public ?string $bloodGroup = null,

        #[Assert\Length(max: 20)]
        public ?string $mobile = null,

        #[Assert\Length(max: 20)]
        public ?string $alternateMobile = null,

        #[Assert\Length(max: 500)]
        public ?string $profilePhotoReference = null,

        #[Assert\Length(max: 100)]
        public ?string $nationality = null,

        #[Assert\Choice(choices: ['SINGLE', 'MARRIED', 'DIVORCED', 'WIDOWED', 'SEPARATED', 'OTHER'], message: 'Please select a valid marital status.')]
        #[Assert\Length(max: 20)]
        public ?string $maritalStatus = null,

        #[Assert\Length(max: 200)]
        public ?string $emergencyContactName = null,

        #[Assert\Length(max: 20)]
        public ?string $emergencyContactPhone = null,

        #[Assert\Length(max: 100)]
        public ?string $emergencyContactRelationship = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            firstName: $data['firstName'] ?? null,
            middleName: $data['middleName'] ?? null,
            lastName: $data['lastName'] ?? null,
            dateOfBirth: $data['dateOfBirth'] ?? null,
            gender: self::normalizeString($data['gender'] ?? null),
            bloodGroup: self::normalizeString($data['bloodGroup'] ?? null),
            mobile: self::normalizeString($data['mobile'] ?? null),
            alternateMobile: self::normalizeString($data['alternateMobile'] ?? null),
            profilePhotoReference: self::normalizeString($data['profilePhotoReference'] ?? null),
            nationality: self::normalizeString($data['nationality'] ?? null),
            maritalStatus: self::normalizeString($data['maritalStatus'] ?? null),
            emergencyContactName: self::normalizeString($data['emergencyContactName'] ?? null),
            emergencyContactPhone: self::normalizeString($data['emergencyContactPhone'] ?? null),
            emergencyContactRelationship: self::normalizeString($data['emergencyContactRelationship'] ?? null),
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
