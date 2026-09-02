<?php
declare(strict_types=1);

namespace App\Enum;

enum ExamAttemptMode: string
{
    case Online = 'ONLINE';
    case Offline = 'OFFLINE';
}
