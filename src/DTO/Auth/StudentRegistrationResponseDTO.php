<?php

declare(strict_types=1);

namespace App\DTO\Auth;

readonly class StudentRegistrationResponseDTO
{
    public function __construct(
        public string $email,
        public bool $emailVerificationRequired = true,
    ) {
    }
}
