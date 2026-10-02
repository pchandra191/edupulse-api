<?php
declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class ProcessingDTO
{
    public function __construct(
        public ?string $stage,
        public string $status,
        public ?string $error,
        public ?string $startedAt,
        public ?string $completedAt,
        public int $retryCount,
    ) {
    }
}