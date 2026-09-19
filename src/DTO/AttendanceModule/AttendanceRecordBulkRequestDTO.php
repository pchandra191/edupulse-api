<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class AttendanceRecordBulkRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 36)]
        public string $academicYearId,

        #[Assert\NotBlank]
        #[Assert\Length(max: 36)]
        public string $classId,

        #[Assert\NotBlank]
        #[Assert\Length(max: 36)]
        public string $sectionId,

        #[Assert\NotBlank]
        #[Assert\Date]
        public string $attendanceDate,

         #[Assert\Valid]
        #[Assert\Count(min: 1, minMessage: 'At least one attendance record is required.')]
        public array $records = [],
    ) {
    }
}
