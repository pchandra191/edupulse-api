<?php

namespace App\DTO\Auth;

use Symfony\Component\Validator\Constraints as Assert;

readonly class ForgotPasswordRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,
    ) {
    }
}
