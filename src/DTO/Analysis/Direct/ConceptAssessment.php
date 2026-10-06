<?php

declare(strict_types=1);

namespace App\DTO\Analysis\Direct;

readonly class ConceptAssessment
{
    public function __construct(
        public string $concept,
        public string $status, // STRONG|DEVELOPING|NEEDS_ATTENTION|INSUFFICIENT_EVIDENCE
        public float $confidence,
        public array $evidence = [],
    ) {
    }
}