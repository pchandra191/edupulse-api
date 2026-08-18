<?php
declare(strict_types=1);

namespace App\DTO\QuestionPaperModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UpdateQuestionRequestDTO
{
    public function __construct(
        #[Assert\Choice(choices: ['MCQ', 'DESCRIPTIVE'])]
        public ?string $type = null,

        public ?string $questionText = null,

        #[Assert\Positive]
        public ?int $marks = null,

        public ?int $displayOrder = null,

        #[Assert\Length(max: 5000)]
        public ?string $image = null,

        #[Assert\All([
            new Assert\NotBlank(),
            new Assert\Length(max: 1000),
        ])]
        public ?array $options = null,
    ) {
    }
}
