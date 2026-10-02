<?php
declare(strict_types=1);

namespace App\DTO\Analysis;

/** @param array<ExtractedQuestionDTO> $extractedQuestions */
/** @param array<ExtractedAnswerDTO> $extractedAnswers */
readonly class ExamDTO
{
    public function __construct(
        public ?string $title,
        public ?string $subject,
        public ?int $totalMarks,
        /** @param array<QuestionDTO> $questions */
        public array $questions,
        public array $extractedQuestions,
        public array $extractedAnswers,
        public ?int $questionCount,
        public ?int $answerCount,
        public ?string $extractedAt,
        public ?string $extractionVersion,
        public array $extractedMetadata = [],
        public string $extractionResult = '',
        public float $extractionConfidence = 0.0,
    ) {
    }
}