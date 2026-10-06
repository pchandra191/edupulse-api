<?php

declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class MetadataConfirmationDTO
{
    public function __construct(
        public string $status,
        public string $confirmedAt,
        public string $confirmedBy,
        public string $source,
    ) {
    }
}