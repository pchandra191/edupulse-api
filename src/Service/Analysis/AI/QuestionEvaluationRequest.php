<?php
declare(strict_types=1);

namespace App\Service\Analysis\AI;

readonly class QuestionEvaluationRequest
{
    public function __construct(
        public string $questionNumber,
        public string $questionText,
        public int $maxMarks,
        public string $questionType,
        public ?string $studentAnswer,
        public ?string $subject,
        public ?array $topics,
        public ?array $concepts,
    ) {
    }
}