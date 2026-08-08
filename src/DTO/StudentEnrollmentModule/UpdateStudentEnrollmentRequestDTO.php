<?php
declare(strict_types=1);

namespace App\DTO\StudentEnrollmentModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UpdateStudentEnrollmentRequestDTO
{
    public function __construct(
        #[Assert\Length(max: 20)]
        public ?string $rollNumber = null,

        #[Assert\Length(max: 50)]
        public ?string $admissionNumber = null,

        public ?string $admissionDate = null,

        #[Assert\Length(max: 1000)]
        public ?string $remarks = null,

        public ?bool $isCurrent = null,

        public ?string $status = null,
    ) {
    }
}
