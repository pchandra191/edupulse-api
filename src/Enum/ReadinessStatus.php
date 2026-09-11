<?php
declare(strict_types=1);

namespace App\Enum;

enum ReadinessStatus: string
{
    case Ready = 'READY';
    case NotReady = 'NOT_READY';
    case Excluded = 'EXCLUDED';
}
