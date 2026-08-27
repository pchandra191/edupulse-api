<?php
declare(strict_types=1);

namespace App\Enum;

enum EducationResult: string
{
    case Pass = 'PASS';
    case Fail = 'FAIL';
    case Pending = 'PENDING';

    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
