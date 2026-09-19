<?php
declare(strict_types=1);

namespace App\DTO\Response;

use App\DTO\AttendanceModule\HolidayResponseDTO;

readonly class HolidayListResponse
{
    /**
     * @param list<HolidayResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public array $pagination,
    ) {
    }
}
