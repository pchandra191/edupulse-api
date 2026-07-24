<?php
declare(strict_types=1);

namespace App\DTO\Auth;

readonly class RefreshResponseDTO
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public ?\DateTimeImmutable $expiresAt,
    ) {
    }
}
