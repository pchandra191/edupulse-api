<?php
declare(strict_types=1);

namespace App\Enum;

enum AttendanceStatus: string
{
    case Present = 'PRESENT';
    case Absent = 'ABSENT';
    case Late = 'LATE';
    case HalfDay = 'HALF_DAY';
    case OnLeave = 'ON_LEAVE';
    case Holiday = 'HOLIDAY';

    public function getLabel(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Absent => 'Absent',
            self::Late => 'Late',
            self::HalfDay => 'Half Day',
            self::OnLeave => 'On Leave',
            self::Holiday => 'Holiday',
        };
    }

    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isPresent(): bool
    {
        return in_array($this, [self::Present, self::Late, self::HalfDay], true);
    }
}
