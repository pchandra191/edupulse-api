<?php
declare(strict_types=1);

namespace App\DTO\ExamAnswerModule;

use App\DTO\QuestionPaperModule\StudentQuestionOptionResponseDTO;
use App\DTO\QuestionPaperModule\StudentQuestionResponseDTO;

readonly class ExamAnswerListResponse
{
    /**
     * @param list<array{answer: ExamAnswerResponseDTO, question: StudentQuestionResponseDTO}> $items
     */
    public function __construct(
        public array $items,
    ) {
    }
}
