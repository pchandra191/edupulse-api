<?php
declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class ExtractedAnswerDTO
{
    public function __construct(
        public string $questionNumber,
        public string $answerText,
        public array $source,
    ) {
    }
}