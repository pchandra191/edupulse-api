<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class AttendanceCorrectionResponseDTO
{
    public function __construct(
        public string $id,
        public ?InstituteReferenceDTO $institute,
        public string $studentId,
        public string $studentName,
        public AcademicYearReferenceDTO $academicYear,
        public ?AcademicClassReferenceDTO $academicClass,
        public ?SectionReferenceDTO $section,
        public string $attendanceRecordId,
        public string $originalStatus,
        public string $correctedStatus,
        public string $reason,
        public ?UserReferenceDTO $requestedBy,
        public ?UserReferenceDTO $approvedBy,
        public string $status,
        public ?string $remarks,
        public string $requestedAt,
        public ?string $approvedAt,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
