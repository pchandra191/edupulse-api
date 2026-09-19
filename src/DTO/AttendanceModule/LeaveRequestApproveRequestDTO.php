<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class LeaveRequestApproveRequestDTO
{
    public function __construct(
        #[Assert\Length(max: 1000)]
        public ?string $remarks = null,
    ) {
    }
}
