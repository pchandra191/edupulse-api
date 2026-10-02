<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class SubjectAttendanceItemDTO
{
    public function __construct(
        public ?string $subjectId,
        public ?string $subjectName,
        public ?string $subjectCode,
        public ?string $className,
        public ?string $classCode,
        public ?string $sectionName,
        public ?string $sectionCode,
        public int $totalClasses,
        public int $present,
        public int $absent,
        public int $late,
        public int $onLeave,
        public float $percentage,
    ) {
    }
}
