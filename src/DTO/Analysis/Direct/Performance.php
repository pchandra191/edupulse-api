<?php

declare(strict_types=1);

namespace App\DTO\Analysis\Direct;

readonly class Performance
{
    public function __construct(
        public ?int $score = null,
        public ?int $totalMarks = null,
        public ?float $percentage = null,
    ) {
    }
}