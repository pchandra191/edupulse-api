<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class AttendanceSummaryDTO
{
    public function __construct(
        public int $totalWorkingDays,
        public int $present,
        public int $absent,
        public int $late,
        public int $halfDay,
        public int $onLeave,
        public int $holiday,
        public float $attendancePercentage,
        public int $totalDays,
        public int $presentDays,
        public int $absentDays,
        public int $lateDays,
        public int $leaveDays,
    ) {
    }
}
