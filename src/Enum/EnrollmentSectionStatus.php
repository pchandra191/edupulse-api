<?php
declare(strict_types=1);

namespace App\Enum;

enum EnrollmentSectionStatus: string
{
    case Active = 'ACTIVE';
    case Left = 'LEFT';
    case Completed = 'COMPLETED';
    case Archived = 'ARCHIVED';
}
