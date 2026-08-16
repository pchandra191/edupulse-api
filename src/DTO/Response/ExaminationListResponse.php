<?php
declare(strict_types=1);

namespace App\DTO\Response;

use App\DTO\ExaminationModule\ExaminationResponseDTO;

readonly class ExaminationListResponse
{
    /**
     * @param list<ExaminationResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public array $pagination,
    ) {
    }
}
