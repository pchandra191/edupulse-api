<?php
declare(strict_types=1);

namespace App\Enum;

enum AddressType: string
{
    case Current = 'CURRENT';
    case Permanent = 'PERMANENT';
    case Emergency = 'EMERGENCY';

    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
