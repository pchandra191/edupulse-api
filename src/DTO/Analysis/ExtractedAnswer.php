<?php

declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class ExtractedAnswer
{
    public function __construct(
        public string $questionNumber,
        public string $answerText,
        public ?int $page = null,
        public float $confidence = 0.8,
        public ?string $sourcePage = null,
    ) {
    }
}