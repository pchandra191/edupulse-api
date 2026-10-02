<?php

declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class DocumentFileDTO
{
    public function __construct(
        public ?string $fileId,
        public string $status,
    ) {
    }
}