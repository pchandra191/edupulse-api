<?php
declare(strict_types=1);

namespace App\Service\QuestionPaperModule;

use App\DTO\QuestionPaperModule\GenerateAiQuestionResponseDTO;
use App\DTO\QuestionPaperModule\QuestionOptionResponseDTO;
use Psr\Log\LoggerInterface;

readonly class MockAiQuestionGenerationService implements AiQuestionGenerationServiceInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function generateQuestion(
        string $subject,
        string $topic,
        string $type,
        string $difficulty,
        int $marks,
        ?string $instruction
    ): GenerateAiQuestionResponseDTO {
        $this->logger->info('Generating AI question.', [
            'subject' => $subject,
            'topic' => $topic,
            'type' => $type,
            'difficulty' => $difficulty,
            'marks' => $marks,
        ]);

        if ($type === 'MCQ') {
            $options = [
                new QuestionOptionResponseDTO(
                    id: '00000000-0000-0000-0000-000000000001',
                    text: 'Option A',
                    displayOrder: 1,
                ),
                new QuestionOptionResponseDTO(
                    id: '00000000-0000-0000-0000-000000000002',
                    text: 'Option B',
                    displayOrder: 2,
                ),
                new QuestionOptionResponseDTO(
                    id: '00000000-0000-0000-0000-000000000003',
                    text: 'Option C',
                    displayOrder: 3,
                ),
                new QuestionOptionResponseDTO(
                    id: '00000000-0000-0000-0000-000000000004',
                    text: 'Option D',
                    displayOrder: 4,
                ),
            ];

            return new GenerateAiQuestionResponseDTO(
                type: 'MCQ',
                questionText: '[AI] What is the primary function of ' . $topic . ' in ' . $subject . '?',
                options: $options,
                marks: $marks,
            );
        }

        return new GenerateAiQuestionResponseDTO(
            type: 'DESCRIPTIVE',
            questionText: '[AI] Explain the concept of ' . $topic . ' in ' . $subject . '.',
            options: null,
            marks: $marks,
        );
    }
}
