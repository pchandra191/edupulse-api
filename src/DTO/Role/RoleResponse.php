<?php
declare(strict_types=1);

namespace App\DTO\Role;

use App\Enum\RoleStatus;

readonly class RoleResponse
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public ?string $description,
        public RoleStatus $status,
    ) {
    }
}
