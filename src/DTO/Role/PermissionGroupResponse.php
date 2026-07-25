<?php
declare(strict_types=1);

namespace App\DTO\Role;

readonly class PermissionGroupResponse
{
    /**
     * @param list<PermissionResponse> $permissions
     */
    public function __construct(
        public string $group,
        public array $permissions,
    ) {
    }
}
