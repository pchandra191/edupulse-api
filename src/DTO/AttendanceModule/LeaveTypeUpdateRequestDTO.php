<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class LeaveTypeUpdateRequestDTO
{
    public function __construct(
        #[Assert\Length(max: 100)]
        public ?string $name = null,

        #[Assert\Length(max: 30)]
        public ?string $code = null,

        #[Assert\Length(max: 1000)]
        public ?string $description = null,

        public ?bool $enabled = null,

        public ?bool $requiresApproval = null,

        public ?int $maximumDays = null,

        public ?bool $carryForward = null,
    ) {
    }
}
