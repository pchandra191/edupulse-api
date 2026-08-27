<?php
declare(strict_types=1);

namespace App\DTO\UserProfile;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UpdateUserBankAccountRequestDTO
{
    public function __construct(
        #[Assert\Length(max: 255)]
        public ?string $accountHolderName = null,

        #[Assert\Length(max: 255)]
        public ?string $accountNumber = null,

        #[Assert\Length(max: 255)]
        public ?string $bankName = null,

        #[Assert\Length(max: 255)]
        public ?string $branchName = null,

        #[Assert\Length(max: 20)]
        public ?string $ifsc = null,

        #[Assert\Choice(choices: ['SAVINGS', 'CURRENT', 'SALARY', 'FIXED_DEPOSIT', 'RECURRING_DEPOSIT', 'OTHER'])]
        public ?string $accountType = null,

        public ?bool $isPrimary = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            accountHolderName: self::normalizeString($data['accountHolderName'] ?? null),
            accountNumber: self::normalizeString($data['accountNumber'] ?? null),
            bankName: self::normalizeString($data['bankName'] ?? null),
            branchName: self::normalizeString($data['branchName'] ?? null),
            ifsc: self::normalizeString($data['ifsc'] ?? null),
            accountType: self::normalizeString($data['accountType'] ?? null),
            isPrimary: isset($data['isPrimary']) ? filter_var($data['isPrimary'], FILTER_VALIDATE_BOOLEAN) : null,
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
