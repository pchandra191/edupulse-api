<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class SubjectAttendanceDTO
{
    public function __construct(
        public array $items,
    ) {
    }
}
