<?php
declare(strict_types=1);

namespace App\DTO\UserProfile;

use App\Enum\EducationLevel;

readonly class UserEducationResponseDTO
{
    public function __construct(
        public string $id,
        public string $institutionName,
        public string $educationLevel,
        public string $qualification,
        public ?string $specialization,
        public string $boardUniversity,
        public ?string $startDate,
        public ?string $endDate,
        public ?string $result,
        public ?string $percentage,
        public ?string $grade,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
