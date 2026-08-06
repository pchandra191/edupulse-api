<?php
declare(strict_types=1);

namespace App\Enum;

enum InstituteStatus: string
{
    case Active = 'ACTIVE';
    
    // Institute exists but cannot login or use the system
    case INACTIVE = 'INACTIVE';

    // Subscription expired / manually suspended
    case SUSPENDED = 'SUSPENDED';

    // Soft deleted / archived
    case DELETED = 'DELETED';
}
