<?php
declare(strict_types=1);

namespace App\DTO\Auth;

use App\Validator\Constraints\PasswordPolicy;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

readonly class StudentRegistrationRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $firstName,

        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public string $email,

        #[Assert\NotBlank]
        #[PasswordPolicy]
        public string $password,

        #[Assert\Length(max: 100)]
        public ?string $lastName = null,

        #[Assert\Length(max: 20)]
        public ?string $phone = null,

        public ?string $confirmPassword = null,

        #[Assert\Length(max: 36)]
        public ?string $instituteId = null,
    ) {
    }

    #[Assert\Callback]
    public function validatePasswordsMatch(ExecutionContextInterface $context): void
    {
        if ($this->confirmPassword !== null && $this->password !== $this->confirmPassword) {
            $context->buildViolation('Password confirmation does not match.')
                ->atPath('confirmPassword')
                ->addViolation();
        }
    }
}
