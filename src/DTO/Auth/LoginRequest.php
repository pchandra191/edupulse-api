<?php

namespace App\DTO\Auth;

use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

readonly class LoginRequest
{
    public function __construct(
        #[NotBlank]
        #[Email]
        public string $email,

        #[NotBlank]
        #[Length(min: 8)]
        public string $password,
    ) {
    }
}
