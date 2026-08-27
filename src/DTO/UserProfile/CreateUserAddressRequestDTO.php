<?php
declare(strict_types=1);

namespace App\DTO\UserProfile;

use App\Enum\AddressType;
use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateUserAddressRequestDTO
{
    public function __construct(
        #[Assert\NotBlank(message: 'Address type is required.')]
        #[Assert\Choice(choices: ['CURRENT', 'PERMANENT', 'EMERGENCY'], message: 'Please select a valid address type.')]
        public string $addressType,

        #[Assert\NotBlank(message: 'Address line 1 is required.')]
        #[Assert\Length(max: 255, maxMessage: 'Address line 1 must not exceed {{ limit }} characters.')]
        public string $addressLine1,

        #[Assert\NotBlank(message: 'City is required.')]
        #[Assert\Length(max: 100, maxMessage: 'City must not exceed {{ limit }} characters.')]
        public string $city,

        #[Assert\NotBlank(message: 'Country is required.')]
        #[Assert\Choice(choices: ['Asia/Kolkata', 'America/New_York', 'Europe/Berlin', 'Europe/London', 'Asia/Dubai', 'Asia/Singapore'], message: 'Please select a valid country/timezone.')]
        public string $country,

        #[Assert\Length(max: 255, maxMessage: 'Address line 2 must not exceed {{ limit }} characters.')]
        public ?string $addressLine2 = null,

        #[Assert\Length(max: 100, maxMessage: 'District must not exceed {{ limit }} characters.')]
        public ?string $district = null,

        #[Assert\Length(max: 100, maxMessage: 'State must not exceed {{ limit }} characters.')]
        public ?string $state = null,

        #[Assert\Length(max: 20, maxMessage: 'Postal code must not exceed {{ limit }} characters.')]
        public ?string $postalCode = null,

        public bool $isPrimary = false,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            addressType: $data['addressType'] ?? $data['type'] ?? '',
            addressLine1: $data['addressLine1'] ?? '',
            city: $data['city'] ?? '',
            country: $data['country'] ?? '',
            addressLine2: $data['addressLine2'] ?? null,
            district: $data['district'] ?? null,
            state: $data['state'] ?? null,
            postalCode: $data['postalCode'] ?? null,
            isPrimary: $data['isPrimary'] ?? false,
        );
    }
}
