<?php
declare(strict_types=1);

namespace App\DTO\Response;

use App\DTO\ClassModule\ClassResponseDTO;

readonly class ClassListResponse
{
    /**
     * @param list<ClassResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public array $pagination,
    ) {
    }
}
