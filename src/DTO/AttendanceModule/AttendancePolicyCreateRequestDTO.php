<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class AttendancePolicyCreateRequestDTO
{
    public function __construct(
        #[Assert\Range(min: 0, max: 100)]
        public int $minimumAttendancePercentage = 75,

        public ?int $lateThresholdCount = null,

        #[Assert\Length(max: 50)]
        public ?string $halfDayBehavior = null,

        public bool $approvedLeaveCounts = false,

        #[Assert\Length(max: 50)]
        public ?string $workingDays = null,

        #[Assert\Length(max: 36)]
        public ?string $academicYearId = null,

        public bool $isActive = true,

        #[Assert\Length(max: 1000)]
        public ?string $description = null,

        #[Assert\Length(max: 36)]
        public ?string $instituteId = null,
    ) {
    }
}
