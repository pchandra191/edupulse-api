<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class AttendanceCorrectionFilterRequest
{
    public function __construct(
        #[Assert\Length(max: 36)]
        public ?string $studentId = null,

        #[Assert\Length(max: 36)]
        public ?string $attendanceRecordId = null,

        #[Assert\Length(max: 20)]
        public ?string $status = null,

        public int $page = 1,

        public int $limit = 10,

        #[Assert\Length(max: 50)]
        public string $sortBy = 'createdAt',

        #[Assert\Length(max: 4)]
        public string $sortOrder = 'DESC',
    ) {
    }
}
