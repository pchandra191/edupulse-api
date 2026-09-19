<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class LeaveRequestCreateRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 36)]
        public string $leaveTypeId,

        #[Assert\NotBlank]
        #[Assert\Date]
        public string $startDate,

        #[Assert\NotBlank]
        #[Assert\Date]
        public string $endDate,

        #[Assert\NotBlank]
        #[Assert\Length(max: 2000)]
        public string $reason,

        #[Assert\Length(max: 500)]
        public ?string $attachment = null,
    ) {
    }
}
