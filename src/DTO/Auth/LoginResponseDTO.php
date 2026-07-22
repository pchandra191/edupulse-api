<?php

declare(strict_types=1);

namespace App\DTO\Auth;

readonly class LoginResponseDTO
{
    public function __construct(
        public string $token,
        public string $refreshToken,
        public ?\DateTimeImmutable $expiresAt,
    ) {
    }
}
