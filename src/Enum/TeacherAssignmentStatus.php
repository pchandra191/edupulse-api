<?php
declare(strict_types=1);

namespace App\Enum;

enum TeacherAssignmentStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Archived = 'ARCHIVED';
}
