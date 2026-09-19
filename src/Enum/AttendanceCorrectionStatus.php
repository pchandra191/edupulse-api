<?php
declare(strict_types=1);

namespace App\Enum;

enum AttendanceCorrectionStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
