<?php
declare(strict_types=1);

namespace App\DTO\ExamAnswerModule;

readonly class ExamAnswerResponseDTO
{
    public function __construct(
        public string $id,
        public string $examAttemptId,
        public string $questionId,
        public string $questionType,
        public ?string $selectedOptionId,
        public array $selectedOptionIds,
        public ?string $answerText,
        public string $updatedAt,
    ) {
    }
}
