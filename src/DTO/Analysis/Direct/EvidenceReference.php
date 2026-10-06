<?php

declare(strict_types=1);

namespace App\DTO\Analysis\Direct;

readonly class EvidenceReference
{
    public function __construct(
        public string $type, // 'answer' | 'question' | 'metadata'
        public string $questionNumber,
        public ?string $subQuestion = null,
        public ?string $reason = null,
        public ?int $page = null,
        public ?array $bbox = null,
    ) {
    }
}