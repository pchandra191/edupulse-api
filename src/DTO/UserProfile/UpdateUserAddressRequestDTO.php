<?php
declare(strict_types=1);

namespace App\DTO\UserProfile;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UpdateUserAddressRequestDTO
{
    public function __construct(
        #[Assert\Choice(choices: ['CURRENT', 'PERMANENT', 'EMERGENCY'], message: 'Please select a valid address type.')]
        public ?string $addressType = null,

        #[Assert\Length(max: 255, maxMessage: 'Address line 1 must not exceed {{ limit }} characters.')]
        public ?string $addressLine1 = null,

        #[Assert\Length(max: 255, maxMessage: 'Address line 2 must not exceed {{ limit }} characters.')]
        public ?string $addressLine2 = null,

        #[Assert\Length(max: 100, maxMessage: 'City must not exceed {{ limit }} characters.')]
        public ?string $city = null,

        #[Assert\Length(max: 100, maxMessage: 'District must not exceed {{ limit }} characters.')]
        public ?string $district = null,

        #[Assert\Length(max: 100, maxMessage: 'State must not exceed {{ limit }} characters.')]
        public ?string $state = null,

        #[Assert\Choice(choices: ['Asia/Kolkata', 'America/New_York', 'Europe/Berlin', 'Europe/London', 'Asia/Dubai', 'Asia/Singapore'], message: 'Please select a valid country/timezone.')]
        public ?string $country = null,

        #[Assert\Length(max: 20, maxMessage: 'Postal code must not exceed {{ limit }} characters.')]
        public ?string $postalCode = null,

        public ?bool $isPrimary = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            addressType: self::normalizeString($data['addressType'] ?? $data['type'] ?? null),
            addressLine1: self::normalizeString($data['addressLine1'] ?? null),
            addressLine2: self::normalizeString($data['addressLine2'] ?? null),
            city: self::normalizeString($data['city'] ?? null),
            district: self::normalizeString($data['district'] ?? null),
            state: self::normalizeString($data['state'] ?? null),
            country: self::normalizeString($data['country'] ?? null),
            postalCode: self::normalizeString($data['postalCode'] ?? null),
            isPrimary: $data['isPrimary'] ?? null,
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
