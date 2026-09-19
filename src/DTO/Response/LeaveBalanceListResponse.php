<?php
declare(strict_types=1);

namespace App\DTO\Response;

use App\DTO\AttendanceModule\LeaveBalanceResponseDTO;

readonly class LeaveBalanceListResponse
{
    /**
     * @param list<LeaveBalanceResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public array $pagination,
    ) {
    }
}
