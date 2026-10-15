<?php

declare(strict_types=1);

namespace App\DTO\Auth;

use Symfony\Component\Validator\Constraints\NotBlank;

readonly class GoogleLoginRequest
{
    public function __construct(
        #[NotBlank]
        public string $idToken,
    ) {
    }
}