<?php
declare(strict_types=1);

namespace App\DTO\QuestionPaperModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class GenerateAiQuestionRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['MCQ', 'DESCRIPTIVE'])]
        public string $type,

        #[Assert\NotBlank]
        public string $topic,

        #[Assert\Choice(choices: ['EASY', 'MEDIUM', 'HARD'])]
        public string $difficulty = 'MEDIUM',

        #[Assert\NotBlank]
        #[Assert\Positive]
        public int $marks = 1,

        #[Assert\Length(max: 1000)]
        public ?string $instruction = null,
    ) {
    }
}
