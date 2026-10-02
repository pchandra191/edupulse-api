<?php
declare(strict_types=1);

namespace App\Service\Analysis\AI;

readonly class QuestionEvaluationResult
{
    public function __construct(
        public string $questionNumber,
        public ResultStatus $resultStatus,
        public int $marksAwarded,
        public int $maxMarks,
        public float $answerQuality,
        public float $reasoningQuality,
        public float $confidence,
        public ?ErrorType $errorType,
        public ?string $errorDescription,
        public ?Difficulty $difficulty,
        public ?float $difficultyConfidence,
        public array $topics,
        public array $concepts,
        public ?Misconception $misconception,
    ) {
    }
}