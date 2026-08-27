<?php
declare(strict_types=1);

namespace App\DTO\UserProfile;

use App\Enum\AddressType;

readonly class UserAddressResponseDTO
{
    public function __construct(
        public string $id,
        public string $addressType,
        public string $addressLine1,
        public ?string $addressLine2,
        public string $city,
        public ?string $district,
        public string $state,
        public string $country,
        public ?string $postalCode,
        public bool $isPrimary,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
