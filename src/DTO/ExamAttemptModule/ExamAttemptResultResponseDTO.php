<?php
declare(strict_types=1);

namespace App\DTO\ExamAttemptModule;

readonly class ExamAttemptResultResponseDTO
{
    public function __construct(
        public string $attemptId,
        public string $examSessionId,
        public string $examSessionTitle,
        public string $status,
        public int $totalMarks,
        public int $maxMarks,
        public ?int $obtainedMarks,
        public ?int $percentage,
        public int $correctAnswers,
        public int $incorrectAnswers,
        public int $unanswered,
        public int $pendingAnswers,
        public bool $requiresManualEvaluation,
        public array $questionResults,
        public ?string $submittedAt,
    ) {
    }

    public function toArray(): array
    {
        return [
            'attemptId' => $this->attemptId,
            'examSessionId' => $this->examSessionId,
            'examSessionTitle' => $this->examSessionTitle,
            'status' => $this->status,
            'totalMarks' => $this->totalMarks,
            'maxMarks' => $this->maxMarks,
            'obtainedMarks' => $this->obtainedMarks,
            'percentage' => $this->percentage,
            'correctAnswers' => $this->correctAnswers,
            'incorrectAnswers' => $this->incorrectAnswers,
            'unanswered' => $this->unanswered,
            'pendingAnswers' => $this->pendingAnswers,
            'requiresManualEvaluation' => $this->requiresManualEvaluation,
            'questionResults' => array_map(
                fn (QuestionEvaluationResultDTO $qr) => $qr->toArray(),
                $this->questionResults,
            ),
            'submittedAt' => $this->submittedAt,
        ];
    }
}
