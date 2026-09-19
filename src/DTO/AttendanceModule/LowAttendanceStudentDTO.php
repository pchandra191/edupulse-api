<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class LowAttendanceStudentDTO
{
    public function __construct(
        public string $studentId,
        public string $studentName,
        public ?string $rollNumber,
        public string $email,
        public float $attendancePercentage,
        public string $className,
        public ?string $sectionName,
    ) {
    }
}
