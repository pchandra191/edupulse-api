<?php
declare(strict_types=1);

namespace App\DTO\ExaminationModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateExamSubjectRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $subjectId,

        #[Assert\NotBlank]
        #[Assert\Date]
        public string $examDate,

        public ?string $sectionId = null,

        public ?string $startTime = null,
        public ?string $endTime = null,

        #[Assert\PositiveOrZero]
        public ?int $duration = null,

        #[Assert\PositiveOrZero]
        public ?int $maxMarks = null,

        #[Assert\PositiveOrZero]
        public ?int $passingMarks = null,

        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['DRAFT', 'SCHEDULED', 'ONGOING', 'COMPLETED', 'CANCELLED', 'ARCHIVED'], message: 'Invalid status. Allowed values: DRAFT, SCHEDULED, ONGOING, COMPLETED, CANCELLED, ARCHIVED.')]
        public string $status = 'DRAFT',

        public ?string $remarks = null,
    ) {
    }
}
