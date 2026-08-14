<?php
declare(strict_types=1);

namespace App\DTO\TeacherAssignmentModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateTeacherAssignmentRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $teacherId,

        #[Assert\NotBlank]
        public string $academicYearId,

        #[Assert\NotBlank]
        public string $academicClassId,

        #[Assert\NotBlank]
        #[Assert\Length(max: 30)]
        public string $assignmentType,

        #[Assert\Length(max: 36)]
        public ?string $sectionId = null,

        #[Assert\Length(max: 36)]
        public ?string $subjectId = null,

        #[Assert\Length(max: 1000)]
        public ?string $remarks = null,

        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['ACTIVE', 'INACTIVE', 'TRANSFERRED', 'ARCHIVED'])]
        public string $status = 'ACTIVE',

        #[Assert\Length(max: 36)]
        public ?string $instituteId = null,
    ) {
    }
}
