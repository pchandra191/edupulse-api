<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class StudentAttendanceCalendarDTO
{
    /**
     * @param list<AttendanceCalendarItemDTO> $items
     */
    public function __construct(
        public int $year,
        public int $month,
        public array $items,
    ) {
    }
}
