<?php
declare(strict_types=1);

namespace App\Enum\Analysis;

enum TextSufficiencyDecision: string
{
    case TextSufficient = 'TEXT_SUFFICIENT';
    case VisualExtractionRequired = 'VISUAL_EXTRACTION_REQUIRED';

    public function isSufficient(): bool
    {
        return $this === self::TextSufficient;
    }
}