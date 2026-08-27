<?php
declare(strict_types=1);

namespace App\DTO\UserProfile;

use App\Enum\BankAccountType;
use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateUserBankAccountRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $accountHolderName,

        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $accountNumber,

        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $bankName,

        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['SAVINGS', 'CURRENT', 'SALARY', 'FIXED_DEPOSIT', 'RECURRING_DEPOSIT', 'OTHER'])]
        public string $accountType,

        #[Assert\Length(max: 255)]
        public ?string $branchName = null,

        #[Assert\Length(max: 20)]
        public ?string $ifsc = null,

        public bool $isPrimary = false,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            accountHolderName: $data['accountHolderName'] ?? '',
            accountNumber: $data['accountNumber'] ?? '',
            bankName: $data['bankName'] ?? '',
            accountType: $data['accountType'] ?? 'SAVINGS',
            branchName: $data['branchName'] ?? null,
            ifsc: $data['ifsc'] ?? null,
            isPrimary: filter_var($data['isPrimary'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }
}
