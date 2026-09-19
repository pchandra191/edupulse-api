<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class StudentAttendancePolicyDTO
{
    public function __construct(
        public ?string $minimumAttendancePercentage = null,
        public ?int $lateThresholdCount = null,
        public ?bool $approvedLeaveCounts = null,
        public ?string $description = null,
    ) {
    }
}
