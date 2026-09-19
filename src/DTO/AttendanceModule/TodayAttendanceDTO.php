<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class TodayAttendanceDTO
{
    public function __construct(
        public int $totalMarked,
        public int $present,
        public int $absent,
        public int $late,
        public int $onLeave,
        public int $notMarked,
    ) {
    }
}
