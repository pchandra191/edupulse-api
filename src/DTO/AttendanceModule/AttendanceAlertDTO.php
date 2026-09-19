<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class AttendanceAlertDTO
{
    public function __construct(
        public string $type,
        public string $severity,
        public ?string $subjectId,
        public ?string $subjectName,
        public ?float $attendancePercentage,
        public ?int $requiredPercentage,
        public string $message,
    ) {
    }
}
