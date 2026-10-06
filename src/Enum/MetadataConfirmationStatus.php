<?php

declare(strict_types=1);

namespace App\Enum;

enum MetadataConfirmationStatus: string
{
    case Pending = 'PENDING';
    case Confirmed = 'CONFIRMED';
}