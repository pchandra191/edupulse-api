<?php
declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class ProvenanceValueDTO
{
    public function __construct(
        public mixed $value,
        public string $status,
        public float $confidence,
    ) {
    }
}