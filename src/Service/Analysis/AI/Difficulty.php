<?php
declare(strict_types=1);

namespace App\Service\Analysis\AI;

enum Difficulty: string
{
    case Easy = 'EASY';
    case Medium = 'MEDIUM';
    case Hard = 'HARD';
    case Unknown = 'UNKNOWN';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}