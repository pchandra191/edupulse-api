<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class HolidayResponseDTO
{
    public function __construct(
        public string $id,
        public ?InstituteReferenceDTO $institute,
        public ?AcademicYearReferenceDTO $academicYear,
        public ?AcademicClassReferenceDTO $academicClass,
        public ?SectionReferenceDTO $section,
        public string $date,
        public string $name,
        public ?string $description,
        public bool $enabled,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
