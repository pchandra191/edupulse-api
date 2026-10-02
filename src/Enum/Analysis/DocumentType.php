<?php
declare(strict_types=1);

namespace App\Enum\Analysis;

enum DocumentType: string
{
    case AnswerSheet = 'ANSWER_SHEET';
    case QuestionPaper = 'QUESTION_PAPER';
    case Unknown = 'UNKNOWN';

    public function isAnswerSheet(): bool
    {
        return $this === self::AnswerSheet;
    }

    public function isQuestionPaper(): bool
    {
        return $this === self::QuestionPaper;
    }

    public function isUnknown(): bool
    {
        return $this === self::Unknown;
    }
}