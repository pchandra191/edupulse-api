<?php
declare(strict_types=1);

namespace App\Enum;

enum Currency: string
{
    case INR = 'INR';
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case AED = 'AED';
    case SGD = 'SGD';

    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
