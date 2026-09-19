<?php
declare(strict_types=1);

namespace App\DTO\Response;

use App\DTO\AttendanceModule\LeaveRequestResponseDTO;

readonly class LeaveRequestListResponse
{
    /**
     * @param list<LeaveRequestResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public array $pagination,
    ) {
    }
}
