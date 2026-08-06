<?php
declare(strict_types=1);

namespace App\Enum;

enum ClassStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Archived = 'ARCHIVED';
}
