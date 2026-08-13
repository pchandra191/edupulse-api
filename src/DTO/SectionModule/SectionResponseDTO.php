<?php
declare(strict_types=1);

namespace App\DTO\SectionModule;

use App\Entity\AcademicClass;

readonly class SectionResponseDTO
{
    public function __construct(
        public ?InstituteResponse $institute,
        public string $id,
        public ?AcademicYearResponse $academicYear,
        public string $academicClassId,
        public ?AcademicClassResponse $academicClass,
        public string $name,
        public string $code,
        public ?int $capacity,
        public ?string $description,
        public string $status,
        public string $createdAt,
        public ?string $updatedAt,
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
    ) {
    }
}
