<?php
declare(strict_types=1);

namespace App\Enum;

enum ExamSessionStatus: string
{
    case Draft = 'DRAFT';
    case Scheduled = 'SCHEDULED';
    case Published = 'PUBLISHED';
    case Active = 'ACTIVE';
    case Closed = 'CLOSED';
    case Cancelled = 'CANCELLED';
    case Archived = 'ARCHIVED';
}
