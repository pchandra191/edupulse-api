<?php
declare(strict_types=1);

namespace App\DTO\UserProfile;

use App\Enum\GovernmentIdType;
use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateUserGovernmentIdentifierRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['AADHAAR', 'PAN', 'DRIVING_LICENCE', 'PASSPORT', 'VOTER_ID', 'OTHER'])]
        public string $type,

        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $identifier,

        #[Assert\Length(max: 100)]
        public ?string $issuingCountry = null,

        #[Assert\Length(max: 255)]
        public ?string $issuingAuthority = null,

        public ?string $issueDate = null,

        public ?string $expiryDate = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] ?? '',
            identifier: self::normalizeString($data['value'] ?? $data['identifier'] ?? '') ?? '',
            issuingCountry: $data['issuingCountry'] ?? null,
            issuingAuthority: $data['issuingAuthority'] ?? null,
            issueDate: $data['issueDate'] ?? null,
            expiryDate: $data['expiryDate'] ?? null,
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
