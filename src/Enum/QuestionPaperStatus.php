<?php
declare(strict_types=1);

namespace App\Enum;

enum QuestionPaperStatus: string
{
    case Draft = 'DRAFT';
    case SubmittedForReview = 'SUBMITTED_FOR_REVIEW';
    case Finalized = 'FINALIZED';
    case Archived = 'ARCHIVED';
}
