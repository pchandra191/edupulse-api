<?php
declare(strict_types=1);

namespace App\DTO\Response;

use App\DTO\SectionModule\SectionResponseDTO;

readonly class SectionListResponse
{
    /**
     * @param list<SectionResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public array $pagination,
    ) {
    }
}
