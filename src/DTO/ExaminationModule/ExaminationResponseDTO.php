<?php
declare(strict_types=1);

namespace App\DTO\ExaminationModule;

readonly class ExaminationResponseDTO
{
    public function __construct(
        public string $id,
        public string $instituteId,
        public string $instituteName,
        public string $academicYearId,
        public string $academicYearName,
        public string $academicClassId,
        public string $academicClassName,
        public string $name,
        public string $code,
        public ?string $description,
        public string $startDate,
        public string $endDate,
        public string $status,
        public ?int $subjectsCount,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}

readonly class AcademicYearResponse
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}

readonly class AcademicClassResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
    ) {
    }
}

readonly class InstituteResponse
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
