<?php
declare(strict_types=1);

namespace App\DTO\Response;

use App\Common\Api\Response\PaginationResponse;
use App\DTO\Institute\InstituteProfileResponseDTO;

readonly class InstituteListResponse
{
    /**
     * @param list<InstituteProfileResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public PaginationResponse $pagination,
    ) {
    }
}
