<?php
declare(strict_types=1);

namespace App\Enum;

enum ExaminationStatus: string
{
    case Draft = 'DRAFT';
    case Scheduled = 'SCHEDULED';
    case Ongoing = 'ONGOING';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case Archived = 'ARCHIVED';
}
