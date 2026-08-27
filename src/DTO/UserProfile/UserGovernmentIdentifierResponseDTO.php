<?php
declare(strict_types=1);

namespace App\DTO\UserProfile;

use App\Enum\GovernmentIdType;

readonly class UserGovernmentIdentifierResponseDTO
{
    public function __construct(
        public string $id,
        public string $type,
        public string $maskedIdentifier,
        public ?string $issuingCountry,
        public ?string $issuingAuthority,
        public ?string $issueDate,
        public ?string $expiryDate,
        public string $verificationStatus,
        public ?string $verifiedAt,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
