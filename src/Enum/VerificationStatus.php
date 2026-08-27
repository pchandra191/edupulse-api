<?php
declare(strict_types=1);

namespace App\Enum;

enum VerificationStatus: string
{
    case Unverified = 'UNVERIFIED';
    case Pending = 'PENDING';
    case Verified = 'VERIFIED';
    case Rejected = 'REJECTED';

    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
