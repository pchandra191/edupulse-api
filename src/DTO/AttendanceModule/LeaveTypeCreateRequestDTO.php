<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class LeaveTypeCreateRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $name,

        #[Assert\NotBlank]
        #[Assert\Length(max: 30)]
        public string $code,

        #[Assert\Length(max: 1000)]
        public ?string $description = null,

        public bool $enabled = true,

        public bool $requiresApproval = true,

        public ?int $maximumDays = null,

        public bool $carryForward = false,

        #[Assert\Length(max: 36)]
        public ?string $instituteId = null,
    ) {
    }
}
