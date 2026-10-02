<?php
declare(strict_types=1);

namespace App\Service\Analysis\AI;

enum ResultStatus: string
{
    case Correct = 'CORRECT';
    case PartiallyCorrect = 'PARTIALLY_CORRECT';
    case Incorrect = 'INCORRECT';
    case Unanswered = 'UNANSWERED';
    case NotEvaluatable = 'NOT_EVALUATABLE';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}