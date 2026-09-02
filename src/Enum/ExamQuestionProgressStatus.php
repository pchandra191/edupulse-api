<?php
declare(strict_types=1);

namespace App\Enum;

enum ExamQuestionProgressStatus: string
{
    case NotAttempted = 'NOT_ATTEMPTED';
    case AttemptedUnanswered = 'ATTEMPTED_UNANSWERED';
    case Answered = 'ANSWERED';
}
