<?php
declare(strict_types=1);

namespace App\Tests\Service\Analysis\AI;

use App\Service\Analysis\AI\OpenAiProvider;
use App\Service\Analysis\AI\QuestionEvaluationRequest;
use App\Service\Analysis\AI\QuestionEvaluationResult;
use App\Service\Analysis\AI\ResultStatus;
use App\Service\Analysis\AI\ErrorType;
use App\Service\Analysis\AI\Difficulty;
use App\Service\Analysis\AI\Misconception;
use Psr\Log\NullLogger;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\ClientExceptionInterface;
use PHPUnit\Framework\TestCase;

class OpenAiProviderTest extends TestCase
{
    private OpenAiProvider $provider;
    private ClientInterface $httpClient;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->provider = new OpenAiProvider(
            'test-api-key',
            'gpt-4o-mini',
            new NullLogger(),
            $this->httpClient
        );
    }

    public function testEvaluateQuestionReturnsResult(): void
    {
        $request = new QuestionEvaluationRequest(
            questionNumber: '1',
            questionText: 'What is 2+2?',
            maxMarks: 2,
            questionType: 'SHORT_ANSWER',
            studentAnswer: '4',
            subject: 'Mathematics',
            topics: ['Arithmetic'],
            concepts: ['Addition'],
        );

        $mockResponse = json_encode([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'resultStatus' => 'CORRECT',
                        'marksAwarded' => 2,
                        'answerQuality' => 1.0,
                        'reasoningQuality' => 1.0,
                        'confidence' => 0.95,
                        'errorType' => 'NONE',
                        'errorDescription' => null,
                        'difficulty' => 'EASY',
                        'difficultyConfidence' => 0.9,
                        'topics' => [['name' => 'Arithmetic', 'confidence' => 0.9]],
                        'concepts' => [['name' => 'Addition', 'confidence' => 0.9]],
                        'misconception' => ['identified' => false],
                    ]),
                ],
            ]],
        ]);

        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('getContents')->willReturn($mockResponse);

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($stream);

        $this->httpClient->method('sendRequest')
            ->willReturnCallback(function ($request) use ($response) {
                return $response;
            });

        $result = $this->provider->evaluateQuestion($request);

        $this->assertInstanceOf(QuestionEvaluationResult::class, $result);
        $this->assertSame('1', $result->questionNumber);
        $this->assertSame(ResultStatus::Correct, $result->resultStatus);
        $this->assertSame(2, $result->marksAwarded);
        $this->assertSame(ErrorType::None, $result->errorType);
    }

    public function testEvaluateQuestionHandlesUnanswered(): void
    {
        $request = new QuestionEvaluationRequest(
            questionNumber: '1',
            questionText: 'What is 2+2?',
            maxMarks: 2,
            questionType: 'SHORT_ANSWER',
            studentAnswer: null,
            subject: 'Mathematics',
            topics: ['Arithmetic'],
            concepts: ['Addition'],
        );

        $mockResponse = json_encode([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'resultStatus' => 'UNANSWERED',
                        'marksAwarded' => 0,
                        'answerQuality' => 0.0,
                        'reasoningQuality' => 0.0,
                        'confidence' => 1.0,
                        'errorType' => 'NONE',
                        'errorDescription' => null,
                        'difficulty' => 'EASY',
                        'difficultyConfidence' => 0.9,
                        'topics' => [['name' => 'Arithmetic', 'confidence' => 0.9]],
                        'concepts' => [['name' => 'Addition', 'confidence' => 0.9]],
                        'misconception' => ['identified' => false],
                    ]),
                ],
            ]],
        ]);

        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('getContents')->willReturn($mockResponse);

        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($stream);

        $this->httpClient->method('sendRequest')
            ->willReturnCallback(function ($request) use ($response) {
                return $response;
            });

        $result = $this->provider->evaluateQuestion($request);

        $this->assertSame(ResultStatus::Unanswered, $result->resultStatus);
        $this->assertSame(0, $result->marksAwarded);
        $this->assertSame(ErrorType::None, $result->errorType);
    }

    public function testEvaluateQuestionReturnsNotEvaluatableOnApiError(): void
    {
        $request = new QuestionEvaluationRequest(
            questionNumber: '1',
            questionText: 'What is 2+2?',
            maxMarks: 2,
            questionType: 'SHORT_ANSWER',
            studentAnswer: '4',
            subject: 'Mathematics',
            topics: ['Arithmetic'],
            concepts: ['Addition'],
        );

        $this->httpClient->method('sendRequest')->willThrowException(
            new class('API Error') extends \Exception implements ClientExceptionInterface {}
        );

        $result = $this->provider->evaluateQuestion($request);

        $this->assertSame(ResultStatus::NotEvaluatable, $result->resultStatus);
        $this->assertSame(0, $result->marksAwarded);
        $this->assertSame(ErrorType::Unknown, $result->errorType);
    }

    public function testGetProviderName(): void
    {
        $this->assertSame('openai', $this->provider->getProviderName());
    }

    public function testGetModelName(): void
    {
        $this->assertSame('gpt-4o-mini', $this->provider->getModelName());
    }
}