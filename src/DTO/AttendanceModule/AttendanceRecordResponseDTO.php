<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class AttendanceRecordResponseDTO
{
    public function __construct(
        public string $id,
        public ?InstituteReferenceDTO $institute,
        public StudentReferenceDTO $student,
        public AcademicYearReferenceDTO $academicYear,
        public AcademicClassReferenceDTO $academicClass,
        public ?SectionReferenceDTO $section,
        public string $attendanceDate,
        public string $status,
        public ?UserReferenceDTO $markedBy,
        public ?string $markedAt,
        public ?string $remarks,
        public string $source,
        public ?float $latitude,
        public ?float $longitude,
        public ?float $accuracyMeters,
        public ?string $locationCapturedAt,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
