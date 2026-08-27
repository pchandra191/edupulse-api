<?php
declare(strict_types=1);

namespace App\Enum;

enum UserGender: string
{
    case Male = 'MALE';
    case Female = 'FEMALE';
    case Other = 'OTHER';
    case PreferNotToSay = 'PREFER_NOT_TO_SAY';

    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
