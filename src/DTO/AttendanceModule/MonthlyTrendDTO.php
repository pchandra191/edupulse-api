<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class MonthlyTrendDTO
{
    public function __construct(
        public int $month,
        public string $monthName,
        public int $year,
        public float $attendancePercentage,
        public int $presentCount,
        public int $absentCount,
        public int $lateCount,
        public int $onLeaveCount,
    ) {
    }
}
