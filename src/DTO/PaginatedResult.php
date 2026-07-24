<?php
declare(strict_types=1);

namespace App\DTO;

readonly class PaginatedResult
{
    /**
     * @param list<User> $items
     * @param array{page: int, limit: int, totalItems: int, totalPages: int} $pagination
     */
    public function __construct(
        public array $items,
        public array $pagination,
    ) {
    }
}
