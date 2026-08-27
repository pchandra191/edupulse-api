<?php
declare(strict_types=1);

namespace App\Enum;

enum MaritalStatus: string
{
    case Single = 'SINGLE';
    case Married = 'MARRIED';
    case Divorced = 'DIVORCED';
    case Widowed = 'WIDOWED';
    case Separated = 'SEPARATED';
    case Other = 'OTHER';

    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
