<?php
declare(strict_types=1);

namespace App\Service\Analysis\AI;

use Psr\Log\LoggerInterface;
use Psr\Http\Client\ClientInterface;

readonly class OpenAiProvider implements AiProviderInterface
{
    private const MAX_RETRIES = 2;
    private const RETRY_DELAY_MS = 1000;

    public function __construct(
        private string $apiKey,
        private string $model,
        private LoggerInterface $logger,
        private ClientInterface $httpClient,
    ) {
    }

    public function evaluateQuestion(QuestionEvaluationRequest $request): QuestionEvaluationResult
    {
        $prompt = $this->buildPrompt($request);
        
        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = $this->callApi($prompt);
                $result = $this->parseResponse($response, $request);
                
                if ($this->validateResult($result, $request)) {
                    return $result;
                }
                
                $this->logger->warning('AI response validation failed, retrying', [
                    'question_number' => $request->questionNumber,
                    'attempt' => $attempt + 1,
                    'max_retries' => self::MAX_RETRIES,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('AI evaluation failed', [
                    'question_number' => $request->questionNumber,
                    'attempt' => $attempt + 1,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < self::MAX_RETRIES) {
                usleep(self::RETRY_DELAY_MS * 1000);
            }
        }

        // Return NOT_EVALUATABLE after all retries failed
        return new QuestionEvaluationResult(
            questionNumber: $request->questionNumber,
            resultStatus: ResultStatus::NotEvaluatable,
            marksAwarded: 0,
            maxMarks: $request->maxMarks,
            answerQuality: 0.0,
            reasoningQuality: 0.0,
            confidence: 0.0,
            errorType: ErrorType::Unknown,
            errorDescription: 'AI evaluation failed after retries',
            difficulty: Difficulty::Unknown,
            difficultyConfidence: 0.0,
            topics: [],
            concepts: [],
            misconception: Misconception::notIdentified(),
        );
    }

    private function buildPrompt(QuestionEvaluationRequest $request): string
    {
        $answer = $request->studentAnswer ?? '(No answer provided)';
        $subject = $request->subject ?? 'Unknown';
        $topics = empty($request->topics) ? 'None' : implode(', ', $request->topics);
        $concepts = empty($request->concepts) ? 'None' : implode(', ', $request->concepts);
        
        return <<<PROMPT
You are an expert exam evaluator. Evaluate the student's answer to the following question.

QUESTION:
- Number: {$request->questionNumber}
- Text: {$request->questionText}
- Type: {$request->questionType}
- Maximum Marks: {$request->maxMarks}
- Subject: {$subject}
- Topics: {$topics}
- Concepts: {$concepts}

STUDENT'S ANSWER:
{$answer}

INSTRUCTIONS:
Evaluate the answer and return ONLY a valid JSON object with the following structure:
{
  "resultStatus": "CORRECT|PARTIALLY_CORRECT|INCORRECT|UNANSWERED|NOT_EVALUATABLE",
  "marksAwarded": <integer 0 to {$request->maxMarks}>,
  "answerQuality": <float 0.0 to 1.0>,
  "reasoningQuality": <float 0.0 to 1.0>,
  "confidence": <float 0.0 to 1.0>,
  "errorType": "CONCEPTUAL|CALCULATION|FORMULA|PROCEDURAL|MISINTERPRETATION|CARELESS|INCOMPLETE_REASONING|KNOWLEDGE_GAP|TIME_OR_ATTEMPT|NONE|UNKNOWN",
  "errorDescription": "<string or null>",
  "difficulty": "EASY|MEDIUM|HARD|UNKNOWN",
  "difficultyConfidence": <float 0.0 to 1.0>,
  "topics": [{"name": "<string>", "confidence": <float>}],
  "concepts": [{"name": "<string>", "confidence": <float>}],
  "misconception": {"identified": <bool>, "description": "<string or null>", "confidence": <float or null>}
}

RULES:
- If answer is empty or "No answer provided", resultStatus must be "UNANSWERED", marksAwarded = 0, errorType = "NONE"
- If answer is correct, errorType must be "NONE"
- marksAwarded must be between 0 and {$request->maxMarks}
- All confidence values must be between 0.0 and 1.0
- Return ONLY the JSON object, no additional text
PROMPT;
    }

    private function callApi(string $prompt): string
    {
        $requestFactory = new \Http\Factory\Guzzle\RequestFactory();
        $httpRequest = $requestFactory->createRequest('POST', 'https://api.openai.com/v1/chat/completions')
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(
                (new \Http\Factory\Guzzle\StreamFactory())->createStream(json_encode([
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are an expert exam evaluator. Return only valid JSON.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => 1000,
                    'response_format' => ['type' => 'json_object'],
                ]))
            );

        $response = $this->httpClient->sendRequest($httpRequest);
        $body = $response->getBody()->getContents();
        
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException("OpenAI API error: {$response->getStatusCode()} - {$body}");
        }

        $data = json_decode($body, true);
        return $data['choices'][0]['message']['content'] ?? '';
    }

    private function parseResponse(string $response, QuestionEvaluationRequest $request): QuestionEvaluationResult
    {
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new \RuntimeException('Invalid JSON response from AI');
        }

        // Validate required fields
        $requiredFields = ['resultStatus', 'marksAwarded', 'answerQuality', 'reasoningQuality', 'confidence', 'errorType', 'difficulty'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new \RuntimeException("Missing required field: {$field}");
            }
        }

        // Parse enums
        $resultStatus = ResultStatus::tryFrom($data['resultStatus']) ?? ResultStatus::NotEvaluatable;
        $errorType = ErrorType::tryFrom($data['errorType']) ?? ErrorType::Unknown;
        $difficulty = Difficulty::tryFrom($data['difficulty']) ?? Difficulty::Unknown;

        // Parse topics
        $topics = [];
        if (isset($data['topics']) && is_array($data['topics'])) {
            foreach ($data['topics'] as $topic) {
                if (isset($topic['name']) && isset($topic['confidence'])) {
                    $topics[] = [
                        'name' => $topic['name'],
                        'confidence' => max(0.0, min(1.0, (float)$topic['confidence'])),
                    ];
                }
            }
        }

        // Parse concepts
        $concepts = [];
        if (isset($data['concepts']) && is_array($data['concepts'])) {
            foreach ($data['concepts'] as $concept) {
                if (isset($concept['name']) && isset($concept['confidence'])) {
                    $concepts[] = [
                        'name' => $concept['name'],
                        'confidence' => max(0.0, min(1.0, (float)$concept['confidence'])),
                    ];
                }
            }
        }

        // Parse misconception
        $misconception = Misconception::notIdentified();
        if (isset($data['misconception']) && is_array($data['misconception'])) {
            $m = $data['misconception'];
            if (isset($m['identified']) && $m['identified'] === true && isset($m['description'])) {
                $misconception = Misconception::identified(
                    $m['description'],
                    isset($m['confidence']) ? max(0.0, min(1.0, (float)$m['confidence'])) : null
                );
            }
        }

        // Clamp marks
        $marksAwarded = max(0, min($request->maxMarks, (int)($data['marksAwarded'] ?? 0)));

        // Clamp confidence values
        $answerQuality = max(0.0, min(1.0, (float)($data['answerQuality'] ?? 0.0)));
        $reasoningQuality = max(0.0, min(1.0, (float)($data['reasoningQuality'] ?? 0.0)));
        $confidence = max(0.0, min(1.0, (float)($data['confidence'] ?? 0.0)));
        $difficultyConfidence = max(0.0, min(1.0, (float)($data['difficultyConfidence'] ?? 0.0)));

        return new QuestionEvaluationResult(
            questionNumber: $request->questionNumber,
            resultStatus: $resultStatus,
            marksAwarded: $marksAwarded,
            maxMarks: $request->maxMarks,
            answerQuality: $answerQuality,
            reasoningQuality: $reasoningQuality,
            confidence: $confidence,
            errorType: $errorType,
            errorDescription: $data['errorDescription'] ?? null,
            difficulty: $difficulty,
            difficultyConfidence: $difficultyConfidence,
            topics: $topics,
            concepts: $concepts,
            misconception: $misconception,
        );
    }

    private function validateResult(QuestionEvaluationResult $result, QuestionEvaluationRequest $request): bool
    {
        if ($result->marksAwarded < 0 || $result->marksAwarded > $request->maxMarks) {
            return false;
        }

        if ($result->resultStatus === ResultStatus::Unanswered && $result->marksAwarded !== 0) {
            return false;
        }

        if ($result->resultStatus === ResultStatus::Correct && $result->errorType !== ErrorType::None) {
            return false;
        }

        // Validate confidence values are in valid range
        if ($result->confidence < 0.0 || $result->confidence > 1.0) {
            return false;
        }

        if ($result->answerQuality < 0.0 || $result->answerQuality > 1.0) {
            return false;
        }

        if ($result->reasoningQuality < 0.0 || $result->reasoningQuality > 1.0) {
            return false;
        }

        if ($result->difficultyConfidence < 0.0 || $result->difficultyConfidence > 1.0) {
            return false;
        }

        // Validate topic/concept confidences
        foreach ($result->topics as $topic) {
            if (($topic['confidence'] ?? 0) < 0.0 || ($topic['confidence'] ?? 0) > 1.0) {
                return false;
            }
        }

        foreach ($result->concepts as $concept) {
            if (($concept['confidence'] ?? 0) < 0.0 || ($concept['confidence'] ?? 0) > 1.0) {
                return false;
            }
        }

        // Validate misconception confidence if identified
        if ($result->misconception->identified && $result->misconception->confidence !== null) {
            if ($result->misconception->confidence < 0.0 || $result->misconception->confidence > 1.0) {
                return false;
            }
        }

        return true;
    }

    public function getProviderName(): string
    {
        return 'openai';
    }

    public function getModelName(): string
    {
        return $this->model;
    }
}