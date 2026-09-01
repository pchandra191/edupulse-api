<?php
declare(strict_types=1);

namespace App\DTO\ExamAnswerModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class ManualEvaluateAnswerRequestDTO
{
    public function __construct(
        #[Assert\NotNull(message: 'Marks obtained is required.')]
        #[Assert\Type(type: 'integer', message: 'Marks obtained must be an integer.')]
        #[Assert\GreaterThanOrEqual(value: 0, message: 'Marks obtained must be greater than or equal to 0.')]
        public ?int $marksObtained = null,

        public ?string $answerText = null,
    ) {
    }
}
