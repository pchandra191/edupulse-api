<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class MonthlyAttendanceDTO
{
    public function __construct(
        public int $month,
        public string $monthName,
        public int $year,
        public int $totalWorkingDays,
        public int $present,
        public int $absent,
        public int $late,
        public int $halfDay,
        public int $onLeave,
        public float $attendancePercentage,
    ) {
    }
}
