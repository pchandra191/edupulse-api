<?php
declare(strict_types=1);

namespace App\DTO\Role;

use Symfony\Component\Validator\Constraints as Assert;

readonly class RolePermissionsUpdateRequest
{
    /**
     * @param list<string> $permissions
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Permissions list is required.')]
        #[Assert\All(constraints: [new Assert\NotBlank(), new Assert\Type('string')])]
        public array $permissions = [],
    ) {
    }
}
