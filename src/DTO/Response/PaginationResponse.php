<?php
declare(strict_types=1);

namespace App\DTO\Response;

readonly class PaginationResponse
{
    public function __construct(
        public int $page,
        public int $limit,
        public int $totalItems,
        public int $totalPages,
    ) {
    }
}
