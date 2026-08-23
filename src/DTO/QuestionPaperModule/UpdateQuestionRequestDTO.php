<?php
declare(strict_types=1);

namespace App\DTO\QuestionPaperModule;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Assert\Callback(callback: [self::class, 'validateOptions'])]
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

        public ?array $options = null,
    ) {
    }

    /**
     * Each option is either a plain string or an object with "text" (required)
     * and an optional boolean "isCorrect" flag used to mark MCQ answers.
     */
    public static function validateOptions(self $dto, ExecutionContextInterface $context): void
    {
        if ($dto->options === null) {
            return;
        }

        foreach ($dto->options as $index => $option) {
            if (is_string($option)) {
                if (trim($option) === '') {
                    $context->buildViolation('Option text cannot be blank.')
                        ->atPath("options[$index]")
                        ->addViolation();
                }

                continue;
            }

            if (is_array($option)) {
                $text = $option['text'] ?? null;

                if (!is_string($text) || trim($text) === '') {
                    $context->buildViolation('Option text cannot be blank.')
                        ->atPath("options[$index].text")
                        ->addViolation();
                }

                if (array_key_exists('isCorrect', $option) && !is_bool($option['isCorrect'])) {
                    $context->buildViolation('Option isCorrect must be a boolean.')
                        ->atPath("options[$index].isCorrect")
                        ->addViolation();
                }

                continue;
            }

            $context->buildViolation('Option must be a string or an object with text and isCorrect.')
                ->atPath("options[$index]")
                ->addViolation();
        }
    }
}
