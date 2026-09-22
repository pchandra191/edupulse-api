<?php

declare(strict_types=1);

namespace App\DTO\Auth;

readonly class VerifyEmailRequestDTO
{
    public function __construct(
        public string $token,
    ) {
    }
}
