<?php
declare(strict_types=1);

namespace App\DTO\ExamAttemptQuestionProgressModule;

readonly class QuestionProgressListResponseDTO
{
    /**
     * @param array<QuestionProgressResponseDTO> $items
     */
    public function __construct(
        public string $attemptId,
        public array $items,
    ) {
    }
}
