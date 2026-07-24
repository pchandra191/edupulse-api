<?php
declare(strict_types=1);

namespace App\DTO\User;

use App\Enum\UserStatus;

readonly class UserResponse
{
    public function __construct(
        public string $id,
        public string $email,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $phone,
        public ?string $department,
        public array $roles,
        public UserStatus $status,
        public bool $isVerified,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
