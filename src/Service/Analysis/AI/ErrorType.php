<?php
declare(strict_types=1);

namespace App\Service\Analysis\AI;

enum ErrorType: string
{
    case Conceptual = 'CONCEPTUAL';
    case Calculation = 'CALCULATION';
    case Formula = 'FORMULA';
    case Procedural = 'PROCEDURAL';
    case Misinterpretation = 'MISINTERPRETATION';
    case Careless = 'CARELESS';
    case IncompleteReasoning = 'INCOMPLETE_REASONING';
    case KnowledgeGap = 'KNOWLEDGE_GAP';
    case TimeOrAttempt = 'TIME_OR_ATTEMPT';
    case None = 'NONE';
    case Unknown = 'UNKNOWN';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}