<?php
declare(strict_types=1);

namespace App\DTO\Role;

readonly class PermissionResponse
{
    public function __construct(
        public string $module,
        public string $groupName,
        public string $code,
        public string $name,
        public ?string $description,
    ) {
    }
}
