<?php
declare(strict_types=1);

namespace App\DTO\Analysis;

use Symfony\Component\Validator\Constraints as Assert;

readonly class ReviewAnalysisRequestDTO
{
    public function __construct(
        #[Assert\NotBlank(message: 'Exam data is required.')]
        #[Assert\Valid]
        public ExamReviewDTO $exam,

        #[Assert\NotBlank(message: 'Questions are required.')]
        #[Assert\Count(min: 1, minMessage: 'At least one question is required.')]
        #[Assert\Valid]
        /** @var array<QuestionReviewDTO> */
        public array $questions,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $examData = $data['exam'] ?? [];
        $questionsData = $data['questions'] ?? [];

        $exam = new ExamReviewDTO(
            title: $examData['title'] ?? '',
            subject: $examData['subject'] ?? '',
            totalMarks: $examData['totalMarks'] ?? null,
        );

        $questions = array_map(function (array $q) {
            return new QuestionReviewDTO(
                questionNumber: $q['questionNumber'] ?? '',
                questionText: $q['questionText'] ?? '',
                marks: $q['marks'] ?? null,
                type: $q['type'] ?? null,
            );
        }, $questionsData);

        return new self(
            exam: $exam,
            questions: $questions,
        );
    }
}

readonly class ExamReviewDTO
{
    public function __construct(
        #[Assert\NotBlank(message: 'Exam title is required.')]
        #[Assert\Length(max: 255, maxMessage: 'Exam title must not exceed 255 characters.')]
        public string $title,

        #[Assert\NotBlank(message: 'Exam subject is required.')]
        #[Assert\Length(max: 255, maxMessage: 'Exam subject must not exceed 255 characters.')]
        public string $subject,

        #[Assert\PositiveOrZero(message: 'Total marks must be a positive number or zero.')]
        public ?int $totalMarks,
    ) {
    }
}

readonly class QuestionReviewDTO
{
    public function __construct(
        #[Assert\NotBlank(message: 'Question number is required.')]
        #[Assert\Length(max: 50, maxMessage: 'Question number must not exceed 50 characters.')]
        public string $questionNumber,

        #[Assert\NotBlank(message: 'Question text is required.')]
        public string $questionText,

        #[Assert\NotNull(message: 'Marks are required.')]
        #[Assert\PositiveOrZero(message: 'Marks must be a positive number or zero.')]
        public ?int $marks,

        #[Assert\Length(max: 50, maxMessage: 'Question type must not exceed 50 characters.')]
        public ?string $type,
    ) {
    }
}