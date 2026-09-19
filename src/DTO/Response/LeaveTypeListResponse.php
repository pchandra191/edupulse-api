<?php
declare(strict_types=1);

namespace App\DTO\Response;

use App\DTO\AttendanceModule\LeaveTypeResponseDTO;

readonly class LeaveTypeListResponse
{
    /**
     * @param list<LeaveTypeResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public array $pagination,
    ) {
    }
}
