<?php

namespace App\DTO\Auth;

use App\Validator\Constraints\PasswordPolicy;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\NotBlank;

readonly class ResetPasswordRequestDTO
{
    public function __construct(
        #[NotBlank]
        public string $token,

        #[NotBlank]
        #[PasswordPolicy]
        public string $newPassword,

        #[NotBlank]
        public string $confirmPassword,
    ) {
    }

    #[Assert\Callback]
    public function validatePasswordsMatch(\Symfony\Component\Validator\ExecutionContextInterface $context): void
    {
        if ($this->newPassword !== $this->confirmPassword) {
            $context->buildViolation('Password confirmation does not match.')
                ->atPath('confirmPassword')
                ->addViolation();
        }
    }
}
