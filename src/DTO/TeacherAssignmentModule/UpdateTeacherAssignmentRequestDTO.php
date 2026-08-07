<?php
declare(strict_types=1);

namespace App\DTO\TeacherAssignmentModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UpdateTeacherAssignmentRequestDTO
{
    public function __construct(
        #[Assert\Length(max: 36)]
        public ?string $teacherId = null,

        #[Assert\Length(max: 36)]
        public ?string $academicYearId = null,

        #[Assert\Length(max: 36)]
        public ?string $academicClassId = null,

        #[Assert\Length(max: 36)]
        public ?string $sectionId = null,

        #[Assert\Length(max: 36)]
        public ?string $subjectId = null,

        #[Assert\Length(max: 30)]
        public ?string $assignmentType = null,

        #[Assert\Length(max: 1000)]
        public ?string $remarks = null,

        #[Assert\Length(max: 20)]
        public ?string $status = null,
    ) {
    }
}
