<?php

namespace App\DTO\Auth;

use Symfony\Component\Validator\Constraints\NotBlank;

readonly class RefreshTokenRequestDTO
{
    public function __construct(
        #[NotBlank]
        public string $refreshToken,
    ) {
    }
}
