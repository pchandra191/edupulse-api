<?php
declare(strict_types=1);

namespace App\DTO\QuestionPaperModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateQuestionRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['MCQ', 'DESCRIPTIVE'])]
        public string $type,

        #[Assert\NotBlank]
        public string $questionText,

        #[Assert\NotBlank]
        #[Assert\Positive]
        public int $marks,

        #[Assert\NotBlank]
        public int $displayOrder,

        #[Assert\Length(max: 5000)]
        public ?string $image = null,

        #[Assert\All([
            new Assert\NotBlank(),
            new Assert\Length(max: 1000),
        ])]
        public array $options = [],
    ) {
    }
}
