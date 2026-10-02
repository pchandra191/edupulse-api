<?php

declare(strict_types=1);

namespace App\Enum;

enum AnalysisSource: string
{
    case ExternalUpload = 'EXTERNAL_UPLOAD';
    case EduPulseExam = 'EDUPULSE_EXAM';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}