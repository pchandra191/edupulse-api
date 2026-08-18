<?php
declare(strict_types=1);

namespace App\DTO\QuestionPaperModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateQuestionPaperRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $academicYearId,

        #[Assert\NotBlank]
        public string $subjectId,

        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $title,

        #[Assert\NotBlank]
        #[Assert\Date]
        public string $date,

        #[Assert\NotBlank]
        #[Assert\Positive]
        public int $duration,

        #[Assert\NotBlank]
        #[Assert\PositiveOrZero]
        public int $maximumMarks,

        #[Assert\Choice(choices: ['DRAFT', 'FINALIZED', 'ARCHIVED'], message: 'Invalid status. Allowed values: DRAFT, FINALIZED, ARCHIVED.')]
        public string $status = 'DRAFT',

        #[Assert\Length(max: 5000)]
        public ?string $instructions = null,

        #[Assert\Length(max: 36)]
        public ?string $instituteId = null,

        #[Assert\All([
            new Assert\Uuid(),
        ])]
        public array $classIds = [],

        #[Assert\All([
            new Assert\Uuid(),
        ])]
        public array $sectionIds = [],

        #[Assert\Uuid(message: 'Invalid UUID format.')]
        public ?string $examinationId = null,

        #[Assert\Length(max: 36)]
        public ?string $createdBy = null,
    ) {
    }
}
