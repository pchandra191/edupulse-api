<?php
declare(strict_types=1);

namespace App\Service\QuestionPaperModule;

use App\DTO\QuestionPaperModule\GenerateAiQuestionResponseDTO;
use App\DTO\QuestionPaperModule\QuestionOptionResponseDTO;
use OpenAI\Client;
use OpenAI;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure]
class OpenAiQuestionGenerationService implements AiQuestionGenerationServiceInterface
{
    private ?Client $client = null;

    public function __construct(
        private LoggerInterface $logger,
        private string $openAiApiKey,
        private string $defaultModel = 'gpt-4o-mini',
    ) {
    }

    private function getClient(): Client
    {
        if ($this->client === null) {
            $this->client = OpenAI::client($this->openAiApiKey);
        }

        return $this->client;
    }

    public function generateQuestion(
        string $subject,
        string $topic,
        string $type,
        string $difficulty,
        int $marks,
        ?string $instruction
    ): GenerateAiQuestionResponseDTO {
        $this->logger->info('Generating AI question via OpenAI.', [
            'subject' => $subject,
            'topic' => $topic,
            'type' => $type,
            'difficulty' => $difficulty,
            'marks' => $marks,
        ]);

        $systemPrompt = <<<PROMPT
You are an expert educational question writer. Generate a single high-quality question based on the provided context.

Return ONLY a raw JSON object (no markdown, no code fences, no extra text) with this exact structure:

For MCQ:
{
  "type": "MCQ",
  "questionText": "...",
  "options": [
    {"text": "...", "displayOrder": 1},
    {"text": "...", "displayOrder": 2},
    {"text": "...", "displayOrder": 3},
    {"text": "...", "displayOrder": 4}
  ],
  "marks": 1
}

For DESCRIPTIVE:
{
  "type": "DESCRIPTIVE",
  "questionText": "...",
  "marks": 5
}

Rules:
- Match the requested difficulty: EASY, MEDIUM, HARD
- Match the requested marks
- MCQ must have exactly 4 options with one clearly correct answer
- DESCRIPTIVE questions should require a short to medium length answer
- Do not include any text outside the JSON object
PROMPT;

        $userPrompt = sprintf(
            "Subject: %s\nTopic: %s\nType: %s\nDifficulty: %s\nMarks: %d\n%s",
            $subject,
            $topic,
            $type,
            $difficulty,
            $marks,
            $instruction !== null ? 'Additional instruction: ' . $instruction : ''
        );

        try {
            if (empty($this->openAiApiKey)) {
                throw new \RuntimeException('OpenAI API key is not configured.');
            }

            $response = $this->getClient()->chat()->create([
                'model' => $this->defaultModel,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.7,
                'max_tokens' => 1024,
            ]);

            $content = $response->choices[0]->message->content;

            if (!$content) {
                throw new \App\Common\Exception\BusinessException('AI returned an empty response.');
            }

            $data = json_decode($content, true);

            if (!is_array($data) || !isset($data['type']) || !isset($data['questionText']) || !isset($data['marks'])) {
                throw new \App\Common\Exception\BusinessException('AI returned an invalid response format.');
            }

            $normalizedType = strtoupper($data['type']);
            if (!in_array($normalizedType, ['MCQ', 'DESCRIPTIVE'], true)) {
                throw new \App\Common\Exception\BusinessException('AI returned an invalid question type.');
            }

            $options = null;
            if ($normalizedType === 'MCQ') {
                if (empty($data['options']) || !is_array($data['options'])) {
                    throw new \App\Common\Exception\BusinessException('AI did not return options for MCQ.');
                }

                $options = [];
                foreach ($data['options'] as $index => $option) {
                    $options[] = new QuestionOptionResponseDTO(
                        id: '00000000-0000-0000-0000-00000000000' . ($index + 1),
                        text: is_string($option['text'] ?? null) ? $option['text'] : (string) ($option['text'] ?? 'Option ' . ($index + 1)),
                        displayOrder: isset($option['displayOrder']) ? (int) $option['displayOrder'] : ($index + 1),
                    );
                }
            }

            return new GenerateAiQuestionResponseDTO(
                type: $normalizedType,
                questionText: (string) $data['questionText'],
                options: $options,
                marks: (int) $data['marks'],
            );
        } catch (\App\Common\Exception\BusinessException $e) {
            $this->logger->warning('OpenAI question generation returned invalid data.', [
                'error' => $e->getMessage(),
            ]);

            return $this->generateFallbackQuestion($subject, $topic, $type, $marks);
        } catch (\Throwable $e) {
            $this->logger->warning('OpenAI question generation failed, using fallback.', [
                'error' => $e->getMessage(),
            ]);

            return $this->generateFallbackQuestion($subject, $topic, $type, $marks);
        }
    }

    private function generateFallbackQuestion(string $subject, string $topic, string $type, int $marks): GenerateAiQuestionResponseDTO
    {
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
