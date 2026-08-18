<?php
declare(strict_types=1);

namespace App\DTO\QuestionPaperModule;

use App\DTO\QuestionPaperModule;

readonly class GenerateAiQuestionResponseDTO
{
    /**
     * @param list<QuestionOptionResponseDTO> $options
     */
    public function __construct(
        public string $type,
        public string $questionText,
        public ?array $options,
        public int $marks,
    ) {
    }
}
