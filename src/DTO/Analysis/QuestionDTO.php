<?php

declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class QuestionDTO
{
    /** @param array<string> $options */
    public function __construct(
        public string $questionId,
        public string $questionText,
        public string $type,
        public int $marks,
        public array $options = [],
    ) {
    }
}