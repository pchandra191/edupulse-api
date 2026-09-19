<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class StudentAttendanceRecordDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 36)]
        public string $studentId,

        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['PRESENT', 'ABSENT', 'LATE', 'HALF_DAY', 'ON_LEAVE', 'HOLIDAY'])]
        public string $status,

        #[Assert\Length(max: 500)]
        public ?string $remarks = null,
    ) {
    }
}
