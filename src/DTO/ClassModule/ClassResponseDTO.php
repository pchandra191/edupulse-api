<?php
declare(strict_types=1);

namespace App\DTO\ClassModule;

use App\Entity\AcademicYear;

readonly class ClassResponseDTO
{
    public function __construct(
        public string $id,
        public string $instituteId,
        public string $name,
        public string $code,
        public ?string $description,
        public string $status,
        public ?AcademicYearResponse $academicYear,
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
