<?php
declare(strict_types=1);

namespace App\DTO\ExaminationModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UpdateExamSubjectRequestDTO
{
    public function __construct(
        public ?string $subjectId = null,

        public ?string $sectionId = null,

        #[Assert\Date]
        public ?string $examDate = null,

        public ?string $startTime = null,
        public ?string $endTime = null,

        #[Assert\PositiveOrZero]
        public ?int $duration = null,

        #[Assert\PositiveOrZero]
        public ?int $maxMarks = null,

        #[Assert\PositiveOrZero]
        public ?int $passingMarks = null,

        #[Assert\Choice(choices: ['DRAFT', 'SCHEDULED', 'ONGOING', 'COMPLETED', 'CANCELLED', 'ARCHIVED'], message: 'Invalid status. Allowed values: DRAFT, SCHEDULED, ONGOING, COMPLETED, CANCELLED, ARCHIVED.')]
        public ?string $status = null,

        public ?string $remarks = null,
    ) {
    }
}
