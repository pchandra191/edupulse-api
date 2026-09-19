<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class LeaveBalanceResponseDTO
{
    public function __construct(
        public string $id,
        public ?InstituteReferenceDTO $institute,
        public string $studentId,
        public string $studentName,
        public AcademicYearReferenceDTO $academicYear,
        public LeaveTypeSummaryDTO $leaveType,
        public int $allocated,
        public int $used,
        public int $pending,
        public int $remaining,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
