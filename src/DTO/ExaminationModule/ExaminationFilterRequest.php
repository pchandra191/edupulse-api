<?php
declare(strict_types=1);

namespace App\DTO\ExaminationModule;

readonly class ExaminationFilterRequest
{
    public function __construct(
        public ?string $search = null,
        public ?string $academicYearId = null,
        public ?string $academicClassId = null,
        public ?string $status = null,
        public ?string $startDateFrom = null,
        public ?string $startDateTo = null,
        public ?string $endDateFrom = null,
        public ?string $endDateTo = null,
        public int $page = 1,
        public int $limit = 10,
        public string $sortBy = 'createdAt',
        public string $sortOrder = 'DESC',
    ) {
    }
}
