<?php

declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class DocumentFileDTO
{
    public function __construct(
        public ?string $fileId,
        public string $status,
        public ?string $originalFilename = null,
        public ?string $storedFilename = null,
        public ?string $mimeType = null,
        public ?int $fileSize = null,
        public ?int $page = null,
    ) {
    }
}