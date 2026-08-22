<?php
declare(strict_types=1);

namespace App\DTO\ExamSessionModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UpdateExamSessionRequestDTO
{
    public function __construct(
        #[Assert\Length(max: 255)]
        public ?string $title = null,

        #[Assert\DateTime]
        public ?string $availableFrom = null,

        #[Assert\DateTime]
        public ?string $availableTo = null,

        #[Assert\PositiveOrZero]
        public ?int $durationOverride = null,

        public ?bool $shuffleQuestions = null,

        #[Assert\Choice(choices: ['IMMEDIATE', 'AFTER_DEADLINE', 'MANUAL'], message: 'Invalid showResultsMode.')]
        public ?string $showResultsMode = null,

        #[Assert\Choice(choices: ['OPEN', 'RESTRICTED'], message: 'Invalid accessMode.')]
        public ?string $accessMode = null,

        public ?bool $allowReview = null,

        #[Assert\PositiveOrZero]
        public ?int $totalMarks = null,

        #[Assert\Choice(choices: ['DRAFT', 'SCHEDULED', 'PUBLISHED', 'ACTIVE', 'CLOSED', 'CANCELLED', 'ARCHIVED'], message: 'Invalid status.')]
        public ?string $status = null,
    ) {
    }
}
