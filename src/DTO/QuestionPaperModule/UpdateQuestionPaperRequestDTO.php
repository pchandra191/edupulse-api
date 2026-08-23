<?php
declare(strict_types=1);

namespace App\DTO\QuestionPaperModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UpdateQuestionPaperRequestDTO
{
    public function __construct(
        #[Assert\Length(max: 255)]
        public ?string $title = null,

        #[Assert\Date]
        public ?string $date = null,

        #[Assert\Positive]
        public ?int $duration = null,

        #[Assert\PositiveOrZero]
        public ?int $maximumMarks = null,

        #[Assert\Length(max: 5000)]
        public ?string $instructions = null,

        #[Assert\Choice(choices: ['DRAFT', 'REVIEW', 'FINALIZED', 'ARCHIVED'], message: 'Invalid status. Allowed values: DRAFT, REVIEW, FINALIZED, ARCHIVED.')]
        public ?string $status = null,

        #[Assert\All([
            new Assert\Uuid(),
        ])]
        public ?array $classIds = null,

        #[Assert\All([
            new Assert\Uuid(),
        ])]
        public ?array $sectionIds = null,

        #[Assert\Uuid(message: 'Invalid UUID format.')]
        public ?string $examinationId = null,
    ) {
    }
}
