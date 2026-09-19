<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class DashboardStatsDTO
{
    public function __construct(
        public ?float $overallAttendancePercentage,
        public ?TodayAttendanceDTO $todayAttendance,
        public array $studentsAbsentToday,
        public array $studentsOnLeaveToday,
        public array $lowAttendanceStudents,
        public int $pendingLeaveRequests,
        public array $monthlyAttendanceTrend,
    ) {
    }
}
