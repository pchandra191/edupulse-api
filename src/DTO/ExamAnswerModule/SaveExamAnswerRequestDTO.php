<?php
declare(strict_types=1);

namespace App\DTO\ExamAnswerModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class SaveExamAnswerRequestDTO
{
    public function __construct(
        #[Assert\NotBlank(message: 'Question ID is required.')]
        #[Assert\Uuid(message: 'Invalid question ID format.')]
        public string $questionId,

        #[Assert\Uuid(message: 'Invalid selected option ID format.')]
        public ?string $selectedOptionId = null,

        #[Assert\All([
            new Assert\Uuid(message: 'Invalid selected option ID format.'),
        ])]
        public ?array $selectedOptionIds = null,

        public ?string $answerText = null,
    ) {
    }
}
