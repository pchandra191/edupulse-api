<?php
declare(strict_types=1);

namespace App\DTO\ExaminationModule;

use App\Enum\ReadinessReason;
use App\Enum\ReadinessStatus;
use App\Enum\ExaminationStatus as StatusEnum;

readonly class ExamSubjectResponseDTO
{
    public function __construct(
        public string $id,
        public string $examinationId,
        public string $subjectId,
        public string $subjectName,
        public string $subjectCode,
        public ?string $sectionId,
        public ?string $sectionName,
        public ?string $sectionCode,
        public ?string $examDate,
        public ?string $startTime,
        public ?string $endTime,
        public ?int $duration,
        public ?int $maxMarks,
        public ?int $passingMarks,
        public string $status,
        public ?string $remarks,
        public ?QuestionPaperSummary $questionPaper,
        public ?ExamSessionSummary $examSession,
        public ?ReadinessStatus $readinessStatus,
        public ?ReadinessReason $readinessReason,
        public ?string $readinessMessage,
        public bool $hasSession,
        public bool $isValidated,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}

readonly class SubjectResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
    ) {
    }
}

readonly class SectionResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
    ) {
    }
}
