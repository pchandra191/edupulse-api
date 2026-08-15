<?php
declare(strict_types=1);

namespace App\Enum;

enum Timezone: string
{
    case INR = 'Asia/Kolkata';
    case USD = 'America/New_York';
    case EUR = 'Europe/Berlin';
    case GBP = 'Europe/London';
    case AED = 'Asia/Dubai';
    case SGD = 'Asia/Singapore';

    public function getLabel(): string
    {
        return match ($this) {
            self::INR => 'India (Asia/Kolkata)',
            self::USD => 'United States (America/New_York)',
            self::EUR => 'Germany (Europe/Berlin)',
            self::GBP => 'United Kingdom (Europe/London)',
            self::AED => 'United Arab Emirates (Asia/Dubai)',
            self::SGD => 'Singapore (Asia/Singapore)',
        };
    }

    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
