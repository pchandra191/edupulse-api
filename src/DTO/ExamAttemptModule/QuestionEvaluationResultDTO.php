<?php
declare(strict_types=1);

namespace App\DTO\ExamAttemptModule;

use App\Entity\ExamAnswer;
use App\Entity\Question;
use App\Entity\QuestionOption;

readonly class QuestionEvaluationResultDTO
{
    public function __construct(
        public string $questionId,
        public string $questionType,
        public int $marks,
        public ?int $obtainedMarks,
        public ?string $selectedOptionId,
        public array $selectedOptionIds,
        public ?string $correctOptionId,
        public ?bool $isCorrect,
        public bool $requiresManualEvaluation,
    ) {
    }

    public static function fromEvaluation(
        Question $question,
        ?ExamAnswer $answer,
        ?QuestionOption $correctOption,
        ?int $obtainedMarks,
        ?bool $isCorrect,
        bool $requiresManualEvaluation,
    ): self {
        $selectedOptionId = null;
        $selectedOptionIds = [];

        if ($answer !== null) {
            $selectedOptionId = $answer->getSelectedOption()?->getId()->toRfc4122();
            foreach ($answer->getSelectedOptions() as $selectedOption) {
                $selectedOptionIds[] = $selectedOption->getQuestionOption()->getId()->toRfc4122();
            }
        }

        return new self(
            questionId: $question->getId()->toRfc4122(),
            questionType: $question->getType()->value,
            marks: $question->getMarks(),
            obtainedMarks: $obtainedMarks,
            selectedOptionId: $selectedOptionId,
            selectedOptionIds: $selectedOptionIds,
            correctOptionId: $correctOption?->getId()->toRfc4122(),
            isCorrect: $isCorrect,
            requiresManualEvaluation: $requiresManualEvaluation,
        );
    }

    public function toArray(): array
    {
        return [
            'questionId' => $this->questionId,
            'questionType' => $this->questionType,
            'marks' => $this->marks,
            'obtainedMarks' => $this->obtainedMarks,
            'selectedOptionId' => $this->selectedOptionId,
            'selectedOptionIds' => $this->selectedOptionIds,
            'correctOptionId' => $this->correctOptionId,
            'isCorrect' => $this->isCorrect,
            'requiresManualEvaluation' => $this->requiresManualEvaluation,
        ];
    }
}
