<?php
declare(strict_types=1);

namespace App\DTO\ExamSessionModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateExamSessionRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $questionPaperId,

        #[Assert\NotBlank]
        public string $title,

        #[Assert\NotBlank]
        #[Assert\DateTime(format: 'd-m-Y H:i')]
        public string $availableFrom,

        #[Assert\NotBlank]
        #[Assert\DateTime(format: 'd-m-Y H:i')]
        public string $availableTo,

        #[Assert\NotBlank]
        #[Assert\Uuid(message: 'Invalid UUID format.')]
        public string $academicYearId,

        #[Assert\NotBlank]
        #[Assert\Uuid(message: 'Invalid UUID format.')]
        public string $academicClassId,

        #[Assert\All([
            new Assert\Uuid(message: 'Invalid section UUID.'),
        ])]
        public array $sectionIds = [],

        #[Assert\PositiveOrZero]
        public int $totalMarks = 0,

        #[Assert\Choice(choices: ['DRAFT', 'SCHEDULED', 'PUBLISHED', 'ACTIVE', 'CLOSED', 'CANCELLED', 'ARCHIVED'], message: 'Invalid status.')]
        public string $status = 'DRAFT',

        #[Assert\Uuid(message: 'Invalid UUID format.')]
        public ?string $examinationId = null,

        #[Assert\Uuid(message: 'Invalid UUID format.')]
        public ?string $examSubjectId = null,

        #[Assert\Length(max: 36)]
        public ?string $instituteId = null,

        #[Assert\PositiveOrZero]
        public ?int $durationOverride = null,

        public bool $shuffleQuestions = false,

        #[Assert\Choice(choices: ['IMMEDIATE', 'AFTER_DEADLINE', 'MANUAL'], message: 'Invalid showResultsMode.')]
        public string $showResultsMode = 'IMMEDIATE',

        #[Assert\Choice(choices: ['OPEN', 'RESTRICTED'], message: 'Invalid accessMode.')]
        public string $accessMode = 'OPEN',

        public bool $allowReview = true,
    ) {
    }
}
