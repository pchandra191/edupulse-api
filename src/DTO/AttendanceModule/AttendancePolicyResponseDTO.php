<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class AttendancePolicyResponseDTO
{
    public function __construct(
        public string $id,
        public ?InstituteReferenceDTO $institute,
        public ?AcademicYearReferenceDTO $academicYear,
        public int $minimumAttendancePercentage,
        public ?int $lateThresholdCount,
        public ?string $halfDayBehavior,
        public bool $approvedLeaveCounts,
        public ?string $workingDays,
        public bool $isActive,
        public ?string $description,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
