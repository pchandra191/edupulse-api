<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class AttendanceCorrectionRejectRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 1000)]
        public string $remarks,
    ) {
    }
}
