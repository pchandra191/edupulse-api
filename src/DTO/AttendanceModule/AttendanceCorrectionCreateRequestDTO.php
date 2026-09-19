<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class AttendanceCorrectionCreateRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 36)]
        public string $attendanceRecordId,

        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['PRESENT', 'ABSENT', 'LATE', 'HALF_DAY', 'ON_LEAVE', 'HOLIDAY'])]
        public string $correctedStatus,

        #[Assert\NotBlank]
        #[Assert\Length(max: 2000)]
        public string $reason,
    ) {
    }
}
