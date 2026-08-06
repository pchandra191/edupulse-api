<?php
declare(strict_types=1);

namespace App\DTO\AcademicYear;

readonly class AcademicYearResponseDTO
{
    public function __construct(
        public string $id,
        public string $instituteId,
        public string $name,
        public string $code,
        public string $startDate,
        public string $endDate,
        public bool $isActive,
        public string $status,
        public ?string $description,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
