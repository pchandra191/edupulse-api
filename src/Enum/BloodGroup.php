<?php
declare(strict_types=1);

namespace App\Enum;

enum BloodGroup: string
{
    case APositive = 'A_POSITIVE';
    case ANegative = 'A_NEGATIVE';
    case BPositive = 'B_POSITIVE';
    case BNegative = 'B_NEGATIVE';
    case ABPositive = 'AB_POSITIVE';
    case ABNegative = 'AB_NEGATIVE';
    case OPositive = 'O_POSITIVE';
    case ONegative = 'O_NEGATIVE';

    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
