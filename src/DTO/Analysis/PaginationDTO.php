<?php

declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class PaginationDTO
{
    public function __construct(
        public int $page,
        public int $limit,
        public int $totalItems,
        public int $totalPages,
    ) {
    }
}