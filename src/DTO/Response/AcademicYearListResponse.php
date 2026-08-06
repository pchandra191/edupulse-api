<?php
declare(strict_types=1);

namespace App\DTO\Response;

use App\DTO\AcademicYear\AcademicYearResponseDTO;

readonly class AcademicYearListResponse
{
    /**
     * @param list<AcademicYearResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public array $pagination,
    ) {
    }
}
