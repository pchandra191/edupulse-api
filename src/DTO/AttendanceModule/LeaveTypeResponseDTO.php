<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

readonly class LeaveTypeResponseDTO
{
    public function __construct(
        public string $id,
        public ?InstituteReferenceDTO $institute,
        public string $name,
        public string $code,
        public ?string $description,
        public bool $enabled,
        public bool $requiresApproval,
        public ?int $maximumDays,
        public bool $carryForward,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
