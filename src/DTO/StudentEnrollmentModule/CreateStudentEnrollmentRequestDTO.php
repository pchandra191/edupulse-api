<?php
declare(strict_types=1);

namespace App\DTO\StudentEnrollmentModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateStudentEnrollmentRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $studentId,

        #[Assert\NotBlank]
        public string $academicYearId,

        #[Assert\NotBlank]
        public string $academicClassId,

        #[Assert\NotBlank]
        #[Assert\Length(max: 50)]
        public string $admissionNumber,

        #[Assert\NotBlank]
        public string $admissionDate,

        #[Assert\Length(max: 20)]
        public ?string $rollNumber = null,

        #[Assert\Length(max: 1000)]
        public ?string $remarks = null,

        public bool $isCurrent = false,

        #[Assert\NotBlank]
        public string $status = 'ACTIVE',

        #[Assert\Length(max: 36)]
        public ?string $instituteId = null,
    ) {
    }
}
