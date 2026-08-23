<?php
declare(strict_types=1);

namespace App\DTO\QuestionPaperModule;

readonly class QuestionOptionResponseDTO
{
    public function __construct(
        public string $id,
        public string $text,
        public int $displayOrder,
        public bool $isCorrect = false,
    ) {
    }
}
