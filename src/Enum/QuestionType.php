<?php
declare(strict_types=1);

namespace App\Enum;

enum QuestionType: string
{
    case MCQ = 'MCQ';
    case Descriptive = 'DESCRIPTIVE';
}
