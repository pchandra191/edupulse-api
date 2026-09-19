<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class LeaveTypeSummaryDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
    ) {
    }
}
