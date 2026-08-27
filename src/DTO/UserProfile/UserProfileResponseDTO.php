<?php
declare(strict_types=1);

namespace App\DTO\UserProfile;

use App\Enum\BloodGroup;
use App\Enum\UserGender;

readonly class UserProfileResponseDTO
{
    public function __construct(
        public string $id,
        public ?string $firstName,
        public ?string $middleName,
        public ?string $lastName,
        public ?string $fullName,
        public ?string $dateOfBirth,
        public ?string $gender,
        public ?string $bloodGroup,
        public ?string $mobile,
        public ?string $alternateMobile,
        public ?string $profilePhotoReference,
        public ?string $nationality,
        public ?string $maritalStatus,
        public ?string $emergencyContactName,
        public ?string $emergencyContactPhone,
        public ?string $emergencyContactRelationship,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
