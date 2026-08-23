<?php
declare(strict_types=1);

namespace App\Enum;

enum ExamAttemptStatus: string
{
    case NotStarted = 'NOT_STARTED';
    case InProgress = 'IN_PROGRESS';
    case Submitted = 'SUBMITTED';
    case AutoSubmitted = 'AUTO_SUBMITTED';
    case Abandoned = 'ABANDONED';
}
