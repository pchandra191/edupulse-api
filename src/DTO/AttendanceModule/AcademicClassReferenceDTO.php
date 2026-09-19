<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class AcademicClassReferenceDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
        public ?string $startDate = null,
        public ?string $endDate = null,
    ) {
    }
}
