<?php
declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class AIAnalysisQuestionDTO
{
    public function __construct(
        public string $questionNumber,
        public string $resultStatus,
        public int $marksAwarded,
        public int $maxMarks,
        public float $answerQuality,
        public float $reasoningQuality,
        public float $confidence,
        public ?string $errorType,
        public ?string $errorDescription,
        public ?string $difficulty,
        public ?float $difficultyConfidence,
        public array $topics,
        public array $concepts,
        public ?array $misconception,
    ) {
    }
}