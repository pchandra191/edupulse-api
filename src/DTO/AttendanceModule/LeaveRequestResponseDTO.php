<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class LeaveRequestResponseDTO
{
    public function __construct(
        public string $id,
        public ?InstituteReferenceDTO $institute,
        public StudentReferenceDTO $student,
        public AcademicYearReferenceDTO $academicYear,
        public ?AcademicClassReferenceDTO $academicClass,
        public ?SectionReferenceDTO $section,
        public LeaveTypeSummaryDTO $leaveType,
        public string $startDate,
        public string $endDate,
        public string $reason,
        public ?string $attachment,
        public string $status,
        public ?UserReferenceDTO $approvedBy,
        public ?string $approvalRemarks,
        public string $submittedAt,
        public ?string $approvedAt,
        public ?string $cancelledAt,
        public ?string $cancellationReason,
        public int $totalDays,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
