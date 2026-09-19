<?php
declare(strict_types=1);

namespace App\DTO\StudentEnrollmentModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class StudentEnrollmentStudentsFilterRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Academic year ID is required.')]
        #[Assert\Uuid(message: 'Invalid academic year ID format.')]
        public string $academicYearId,

        #[Assert\NotBlank(message: 'Academic class ID is required.')]
        #[Assert\Uuid(message: 'Invalid academic class ID format.')]
        public string $academicClassId,

        #[Assert\Uuid(message: 'Invalid section ID format.')]
        public ?string $sectionId = null,

        #[Assert\Positive]
        public int $page = 1,

        #[Assert\Positive]
        #[Assert\LessThanOrEqual(100)]
        public int $limit = 10,
    ) {
    }
}
