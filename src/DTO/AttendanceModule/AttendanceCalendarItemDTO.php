<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class AttendanceCalendarItemDTO
{
    public function __construct(
        public string $date,
        public string $dayName,
        public string $status,
        public bool $isWorkingDay,
        public bool $isHoliday,
        public ?string $remarks,
    ) {
    }
}
