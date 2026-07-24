<?php
declare(strict_types=1);

namespace App\DTO\Response;

use App\Common\Api\Response\PaginationResponse;
use App\DTO\User\UserResponse;

readonly class UserListResponse
{
    /**
     * @param list<UserResponse> $items
     */
    public function __construct(
        public array $items,
        public PaginationResponse $pagination,
    ) {
    }
}
