<?php
declare(strict_types=1);

namespace App\Enum;

enum EducationLevel: string
{
    case Secondary = 'SECONDARY';
    case HigherSecondary = 'HIGHER_SECONDARY';
    case Diploma = 'DIPLOMA';
    case Bachelors = 'BACHELORS';
    case Masters = 'MASTERS';
    case Doctorate = 'DOCTORATE';
    case PostDoctorate = 'POST_DOCTORATE';
    case Certificate = 'CERTIFICATE';
    case Other = 'OTHER';

    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
