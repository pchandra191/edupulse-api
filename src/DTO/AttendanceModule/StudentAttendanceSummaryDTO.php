<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class StudentAttendanceSummaryDTO
{
    public function __construct(
        public StudentReferenceDTO $student,
        public AttendanceSummaryDTO $summary,
        public ?array $monthlyStats = null,
    ) {
    }
}
