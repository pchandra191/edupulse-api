<?php
declare(strict_types=1);

namespace App\Enum;

enum StudentEnrollmentStatus: string
{
    case Active = 'ACTIVE';
    case Promoted = 'PROMOTED';
    case Transferred = 'TRANSFERRED';
    case Completed = 'COMPLETED';
    case Dropped = 'DROPPED';
    case Archived = 'ARCHIVED';
}
