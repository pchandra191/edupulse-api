<?php
declare(strict_types=1);

namespace App\DTO\ExaminationModule;

use App\Enum\ReadinessReason;
use App\Enum\ReadinessStatus;

readonly class ExamSubjectReadinessDTO
{
    public function __construct(
        public string $examSubjectId,
        public string $subjectName,
        public ?string $sectionName,
        public string $examSubjectStatus,
        public ?QuestionPaperSummary $questionPaper,
        public ReadinessStatus $readinessStatus,
        public ?ReadinessReason $readinessReason,
        public ?string $readyReasonMessage,
        public bool $hasSession,
        public ?ExamSessionSummary $examSession,
        public bool $isValidated,
        public bool $isActive,
    ) {
    }
}
