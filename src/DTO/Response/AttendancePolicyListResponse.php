<?php
declare(strict_types=1);

namespace App\DTO\Response;

use App\DTO\AttendanceModule\AttendancePolicyResponseDTO;

readonly class AttendancePolicyListResponse
{
    /**
     * @param list<AttendancePolicyResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public array $pagination,
    ) {
    }
}
