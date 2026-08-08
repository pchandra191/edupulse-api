<?php
declare(strict_types=1);

namespace App\DTO\StudentEnrollmentModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class StudentEnrollmentFilterRequest
{
    public function __construct(
        #[Assert\Length(max: 255)]
        public ?string $search = null,

        #[Assert\Length(max: 36)]
        public ?string $studentId = null,

        #[Assert\Length(max: 36)]
        public ?string $academicYearId = null,

        #[Assert\Length(max: 36)]
        public ?string $classId = null,

        #[Assert\Length(max: 36)]
        public ?string $sectionId = null,

        #[Assert\Length(max: 20)]
        public ?string $status = null,

        public int $page = 1,

        public int $limit = 10,

        #[Assert\Length(max: 50)]
        public string $sortBy = 'createdAt',

        #[Assert\Length(max: 4)]
        public string $sortOrder = 'DESC',
    ) {
    }
}
