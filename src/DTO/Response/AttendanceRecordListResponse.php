<?php
declare(strict_types=1);

namespace App\DTO\Response;

use App\DTO\AttendanceModule\AttendanceRecordResponseDTO;

readonly class AttendanceRecordListResponse
{
    /**
     * @param list<AttendanceRecordResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public array $pagination,
    ) {
    }
}
