<?php
declare(strict_types=1);

namespace App\DTO\User;

use App\Validator\Constraints\PasswordPolicy;
use Symfony\Component\Validator\Constraints as Assert;

readonly class AdminResetPasswordRequestDTO
{
    public function __construct(
        #[Assert\NotBlank(message: 'Password is required.')]
        #[Assert\Length(max: 255, maxMessage: 'Password must not exceed {{ limit }} characters.')]
        #[PasswordPolicy]
        public string $newPassword,

        #[Assert\NotBlank(message: 'Password confirmation is required.')]
        #[Assert\Length(max: 255, maxMessage: 'Password confirmation must not exceed {{ limit }} characters.')]
        public string $confirmPassword,
    ) {
    }

    #[Assert\Callback]
    public function validatePasswordsMatch(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if ($this->newPassword !== $this->confirmPassword) {
            $context->buildViolation('Passwords do not match.')
                ->atPath('confirmPassword')
                ->addViolation();
        }
    }
}
