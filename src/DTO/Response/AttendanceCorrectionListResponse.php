<?php
declare(strict_types=1);

namespace App\DTO\Response;

use App\DTO\AttendanceModule\AttendanceCorrectionResponseDTO;

readonly class AttendanceCorrectionListResponse
{
    /**
     * @param list<AttendanceCorrectionResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public array $pagination,
    ) {
    }
}
