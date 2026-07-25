<?php
declare(strict_types=1);

namespace App\DTO\Role;

readonly class UserPermissionsResponse
{
    /**
     * @param list<string> $permissions
     */
    public function __construct(
        public string $role,
        public array $permissions,
    ) {
    }
}
