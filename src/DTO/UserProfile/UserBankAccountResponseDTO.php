<?php
declare(strict_types=1);

namespace App\DTO\UserProfile;

use App\Enum\BankAccountType;

readonly class UserBankAccountResponseDTO
{
    public function __construct(
        public string $id,
        public string $accountHolderName,
        public string $maskedAccountNumber,
        public string $bankName,
        public ?string $branchName,
        public ?string $ifsc,
        public string $accountType,
        public bool $isPrimary,
        public string $verificationStatus,
        public ?string $verifiedAt,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
