<?php
declare(strict_types=1);

namespace App\Enum;

enum ReadinessReason: string
{
    case QuestionPaperMissing = 'QUESTION_PAPER_MISSING';
    case QuestionPaperNotFinalized = 'QUESTION_PAPER_NOT_FINALIZED';
    case QuestionPaperEmpty = 'QUESTION_PAPER_EMPTY';
    case MarksMismatch = 'MARKS_MISMATCH';
    case InvalidConfiguration = 'INVALID_CONFIGURATION';
    case Ready = 'READY';
}
