<?php
declare(strict_types=1);

namespace App\DTO\User;

use App\Enum\UserRole;
use App\Validator\Constraints\PasswordPolicy;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

readonly class CreateUserRequest
{
    /**
     * @param list<UserRole> $roles
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 1, max: 100)]
        public string $firstName,

        #[Assert\NotBlank]
        #[Assert\Length(min: 1, max: 100)]
        public string $lastName,

        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public string $email,

        #[Assert\NotBlank]
        public array $roles,

        #[Assert\NotBlank]
        #[PasswordPolicy]
        public string $password,

        #[Assert\Length(max: 20)]
        public ?string $phone = null,

        #[Assert\Length(max: 100)]
        public ?string $department = null,

        #[Assert\Length(max: 255)]
        public ?string $instituteName = null,
    ) {
    }

    #[Assert\Callback]
    public function validateRoles(ExecutionContextInterface $context): void
    {
        $allowed = [
            UserRole::Student->value,
            UserRole::Teacher->value,
            UserRole::Institute->value,
        ];

        foreach ($this->roles as $role) {
            if (!in_array($role, $allowed, true)) {
                $context->buildViolation('Invalid role supplied.')
                    ->atPath('roles')
                    ->addViolation();
                break;
            }
        }

        if (in_array(UserRole::SuperAdmin->value, $this->roles, true)) {
            $context->buildViolation('Super Admin role cannot be assigned via API.')
                ->atPath('roles')
                ->addViolation();
        }
    }
}
