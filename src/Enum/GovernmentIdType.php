<?php
declare(strict_types=1);

namespace App\Enum;

enum GovernmentIdType: string
{
    case Aadhaar = 'AADHAAR';
    case PAN = 'PAN';
    case DrivingLicence = 'DRIVING_LICENCE';
    case Passport = 'PASSPORT';
    case VoterId = 'VOTER_ID';
    case Other = 'OTHER';

    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
