<?php
declare(strict_types=1);

namespace App\DTO\Institute;

readonly class InstituteOptionResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
        public string $email,
        public ?string $phone,
        public ?string $website,
        public ?string $logo,
        public ?string $addressLine1,
        public ?string $addressLine2,
        public ?string $city,
        public ?string $state,
        public ?string $country,
        public ?string $postalCode,
        public ?string $timezone,
        public ?string $currency,
        public string $status,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
