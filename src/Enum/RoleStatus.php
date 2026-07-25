<?php
declare(strict_types=1);

namespace App\Enum;

enum RoleStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
