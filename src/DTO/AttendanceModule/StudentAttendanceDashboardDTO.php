<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class StudentAttendanceDashboardDTO
{
    public function __construct(
        public ?TodayAttendanceDTO $todayAttendance,
        public ?AttendanceSummaryDTO $summary,
        public ?AttendanceSummaryDTO $monthlySummary,
        public array $monthlyStats,
        public int $pendingLeaveRequests,
        public ?SubjectAttendanceDTO $subjectAttendance = null,
    ) {
    }
}
