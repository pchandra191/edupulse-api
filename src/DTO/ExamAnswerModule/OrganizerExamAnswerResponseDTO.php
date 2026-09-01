<?php
declare(strict_types=1);

namespace App\DTO\ExamAnswerModule;

use App\Entity\ExamAnswer;
use App\Entity\Question;
use App\Entity\QuestionOption;

readonly class OrganizerExamAnswerResponseDTO
{
    public function __construct(
        public string $id,
        public string $questionId,
        public string $questionText,
        public string $questionType,
        public int $marks,
        public ?string $selectedOptionId,
        public ?string $selectedOptionText,
        public array $selectedOptionIds,
        public ?string $answerText,
        public ?string $correctOptionId,
        public ?string $correctOptionText,
        public ?string $referenceAnswer,
        public ?bool $isCorrect,
        public ?int $marksObtained,
        public bool $requiresManualEvaluation,
        public string $answeredAt,
    ) {
    }

    public static function fromEvaluation(
        Question $question,
        ?ExamAnswer $answer,
        ?QuestionOption $correctOption,
        ?bool $isCorrect,
        ?int $marksObtained,
        bool $requiresManualEvaluation,
    ): self {
        $selectedOptionId = null;
        $selectedOptionText = null;
        $selectedOptionIds = [];
        $answerText = null;
        $answeredAt = null;

        if ($answer !== null) {
            $selectedOption = $answer->getSelectedOption();
            $selectedOptionId = $selectedOption?->getId()->toRfc4122();
            $selectedOptionText = $selectedOption?->getOptionText();

            foreach ($answer->getSelectedOptions() as $selectedOption) {
                $selectedOptionIds[] = $selectedOption->getQuestionOption()->getId()->toRfc4122();
            }

            $answerText = $answer->getAnswerText();
            $answeredAt = $answer->getUpdatedAt()->format(\DateTimeInterface::ATOM);
        }

        return new self(
            id: $answer?->getId()->toRfc4122() ?? '',
            questionId: $question->getId()->toRfc4122(),
            questionText: $question->getQuestionText(),
            questionType: $question->getType()->value,
            marks: $question->getMarks(),
            selectedOptionId: $selectedOptionId,
            selectedOptionText: $selectedOptionText,
            selectedOptionIds: $selectedOptionIds,
            answerText: $answerText,
            correctOptionId: $correctOption?->getId()->toRfc4122(),
            correctOptionText: $correctOption?->getOptionText(),
            referenceAnswer: $question->getReferenceAnswer(),
            isCorrect: $isCorrect,
            marksObtained: $marksObtained,
            requiresManualEvaluation: $requiresManualEvaluation,
            answeredAt: $answeredAt,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'questionId' => $this->questionId,
            'questionText' => $this->questionText,
            'questionType' => $this->questionType,
            'marks' => $this->marks,
            'selectedOptionId' => $this->selectedOptionId,
            'selectedOptionText' => $this->selectedOptionText,
            'selectedOptionIds' => $this->selectedOptionIds,
            'answerText' => $this->answerText,
            'correctOptionId' => $this->correctOptionId,
            'correctOptionText' => $this->correctOptionText,
            'referenceAnswer' => $this->referenceAnswer,
            'isCorrect' => $this->isCorrect,
            'marksObtained' => $this->marksObtained,
            'requiresManualEvaluation' => $this->requiresManualEvaluation,
            'answeredAt' => $this->answeredAt,
        ];
    }
}
