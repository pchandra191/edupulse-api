<?php

declare(strict_types=1);

namespace App\DTO\Analysis\Direct;

readonly class Mistake
{
    public function __construct(
        public string $questionNumber,
        public ?string $subQuestion,
        public string $type,
        public string $description,
        public float $confidence,
    ) {
    }
}