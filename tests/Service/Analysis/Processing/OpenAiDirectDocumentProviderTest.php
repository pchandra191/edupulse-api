<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis\Processing;

use App\DTO\Analysis\Direct\CurrentExamAnalysis;
use App\DTO\Analysis\Direct\DirectExtractionResult;
use App\DTO\Analysis\ExtractedAnswerSheetMetadata;
use App\Exception\VisionApiException;
use App\Service\Analysis\AI\Prompt\ExamAnalysisPromptBuilder;
use App\Service\Analysis\Processing\OpenAiDirectDocumentProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class OpenAiDirectDocumentProviderTest extends TestCase
{
    private function createProvider(string $extractionModel = 'gpt-4o', string $analysisModel = 'gpt-4o', string $pdfDetail = 'auto'): OpenAiDirectDocumentProvider
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('debug')->willReturnCallback(fn(...$args) => null);
        $logger->method('info')->willReturnCallback(fn(...$args) => null);
        $logger->method('error')->willReturnCallback(fn(...$args) => null);
        $logger->method('warning')->willReturnCallback(fn(...$args) => null);

        return new OpenAiDirectDocumentProvider(
            $httpClient,
            'test-api-key',
            $extractionModel,
            $analysisModel,
            $pdfDetail,
            $logger,
            '/tmp',
            new ExamAnalysisPromptBuilder(),
        );
    }

    private function createMockResponse(int $statusCode, array $jsonData): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getContent')->willReturn(json_encode($jsonData));
        return $response;
    }

    private function callProtected(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $args);
    }

    public function testConstructorAcceptsPdfDetail(): void
    {
        $provider = $this->createProvider('gpt-4o', 'gpt-4o', 'high');
        $this->assertInstanceOf(OpenAiDirectDocumentProvider::class, $provider);
    }

    public function testValidateExtractionRejectsMissingKeys(): void
    {
        $provider = $this->createProvider();
        $result = $this->callProtected($provider, 'validateExtraction', [['extraction' => ['status' => 'SUCCESS', 'confidence' => 0.9]]]);
        $this->assertFalse($result['usable']);
        $this->assertStringContainsString('Missing required key', $result['reason']);
    }

    public function testValidateExtractionRejectsInvalidStatus(): void
    {
        $provider = $this->createProvider();
        $result = $this->callProtected($provider, 'validateExtraction', [[
            'extractedAnswers' => [],
            'extractedQuestions' => [],
            'extractedMetadata' => [],
            'extraction' => ['status' => 'FAILED', 'confidence' => 0.9],
        ]]);
        $this->assertFalse($result['usable']);
        $this->assertStringContainsString('not SUCCESS', $result['reason']);
    }

    public function testValidateExtractionRejectsInvalidConfidence(): void
    {
        $provider = $this->createProvider();
        $result = $this->callProtected($provider, 'validateExtraction', [[
            'extractedAnswers' => [],
            'extractedQuestions' => [],
            'extractedMetadata' => [],
            'extraction' => ['status' => 'SUCCESS', 'confidence' => 1.5],
        ]]);
        $this->assertFalse($result['usable']);
        $this->assertStringContainsString('Invalid extraction confidence', $result['reason']);
    }

    public function testValidateExtractionRejectsInvalidAnswerConfidence(): void
    {
        $provider = $this->createProvider();
        $result = $this->callProtected($provider, 'validateExtraction', [[
            'extractedAnswers' => [
                ['questionNumber' => '1', 'answerText' => 'test', 'confidence' => -0.1],
            ],
            'extractedQuestions' => [],
            'extractedMetadata' => [],
            'extraction' => ['status' => 'SUCCESS', 'confidence' => 0.9],
        ]]);
        $this->assertFalse($result['usable']);
        $this->assertStringContainsString('Invalid confidence', $result['reason']);
    }

    public function testValidateExtractionRejectsInvalidQuestionMarks(): void
    {
        $provider = $this->createProvider();
        $result = $this->callProtected($provider, 'validateExtraction', [[
            'extractedAnswers' => [],
            'extractedQuestions' => [
                ['questionNumber' => '1', 'questionText' => 'test', 'marks' => -5, 'confidence' => 0.9],
            ],
            'extractedMetadata' => [],
            'extraction' => ['status' => 'SUCCESS', 'confidence' => 0.9],
        ]]);
        $this->assertFalse($result['usable']);
        $this->assertStringContainsString('Invalid marks', $result['reason']);
    }

    public function testValidateAnalysisRejectsMissingCurrentExamAnalysis(): void
    {
        $provider = $this->createProvider();
        $extraction = new DirectExtractionResult(
            metadata: new ExtractedAnswerSheetMetadata(),
            extractedAnswers: [],
            extractedQuestions: [],
            confidence: 0.9,
            extractionMethod: 'TEST'
        );
        $result = $this->callProtected($provider, 'validateAnalysis', [[], $extraction]);
        $this->assertFalse($result['usable']);
        $this->assertStringContainsString('Missing currentExamAnalysis', $result['reason']);
    }

    public function testValidateAnalysisRejectsInvalidConceptStatus(): void
    {
        $provider = $this->createProvider();
        $extraction = new DirectExtractionResult(
            metadata: new ExtractedAnswerSheetMetadata(),
            extractedAnswers: [],
            extractedQuestions: [],
            confidence: 0.9,
            extractionMethod: 'TEST'
        );
        $result = $this->callProtected($provider, 'validateAnalysis', [[
            'currentExamAnalysis' => [
                'strengths' => [],
                'weaknesses' => [],
                'needsAttention' => [],
                'conceptGaps' => [],
                'recommendations' => [],
                'conceptAssessments' => [
                    ['concept' => 'Math', 'status' => 'INVALID_STATUS', 'confidence' => 0.9],
                ],
                'mistakes' => [],
                'evidence' => [],
            ],
        ], $extraction]);
        $this->assertFalse($result['usable']);
        $this->assertStringContainsString('Invalid concept status', $result['reason']);
    }

    public function testValidateAnalysisRejectsInvalidMistakeConfidence(): void
    {
        $provider = $this->createProvider();
        $extraction = new DirectExtractionResult(
            metadata: new ExtractedAnswerSheetMetadata(),
            extractedAnswers: [],
            extractedQuestions: [],
            confidence: 0.9,
            extractionMethod: 'TEST'
        );
        $result = $this->callProtected($provider, 'validateAnalysis', [[
            'currentExamAnalysis' => [
                'strengths' => [],
                'weaknesses' => [],
                'needsAttention' => [],
                'conceptGaps' => [],
                'recommendations' => [],
                'conceptAssessments' => [],
                'mistakes' => [
                    ['questionNumber' => '1', 'type' => 'CALCULATION', 'description' => 'test', 'confidence' => 1.5],
                ],
                'evidence' => [],
            ],
        ], $extraction]);
        $this->assertFalse($result['usable']);
        $this->assertStringContainsString('Invalid confidence', $result['reason']);
    }

    public function testValidateAnalysisRejectsInvalidPerformancePercentage(): void
    {
        $provider = $this->createProvider();
        $extraction = new DirectExtractionResult(
            metadata: new ExtractedAnswerSheetMetadata(),
            extractedAnswers: [],
            extractedQuestions: [],
            confidence: 0.9,
            extractionMethod: 'TEST'
        );
        $result = $this->callProtected($provider, 'validateAnalysis', [[
            'currentExamAnalysis' => [
                'performance' => ['score' => 25, 'totalMarks' => 30, 'percentage' => 150],
                'strengths' => [],
                'weaknesses' => [],
                'needsAttention' => [],
                'conceptGaps' => [],
                'recommendations' => [],
                'conceptAssessments' => [],
                'mistakes' => [],
                'evidence' => [],
            ],
            'questionAnalysis' => [
                [
                    'questionNumber' => '1',
                    'status' => 'STRONG',
                    'question' => 'Q1',
                    'studentAnswer' => 'A1',
                    'evaluation' => [
                        'evaluationStatus' => 'CORRECT',
                        'correctness' => 'CORRECT',
                        'marksAwarded' => 2,
                        'maxMarks' => 2,
                        'whatStudentDidWell' => 'Good',
                        'mistake' => null,
                        'whyItIsWrong' => null,
                        'missingPoints' => [],
                    ],
                    'concept' => [
                        'name' => 'Math',
                        'status' => 'STRONG',
                        'explanation' => 'Good',
                    ],
                    'improvement' => [
                        'whatToLearn' => 'More',
                        'whatToPractice' => 'More',
                        'recommendedAction' => 'More',
                    ],
                    'evidence' => [],
                ],
            ],
        ], $extraction]);
        $this->assertFalse($result['usable']);
        $this->assertStringContainsString('Invalid performance percentage', $result['reason']);
    }

    public function testCallResponsesApiThrowsOn429WithInsufficientQuota(): void
    {
        $provider = $this->createProvider();
        $response = $this->createMockResponse(429, [
            'error' => ['type' => 'insufficient_quota', 'message' => 'quota exceeded'],
        ]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $reflection = new \ReflectionClass($provider);
        $prop = $reflection->getProperty('httpClient');
        $prop->setAccessible(true);
        $prop->setValue($provider, $httpClient);

        $this->expectException(VisionApiException::class);
        $this->expectExceptionMessage('insufficient credits');
        $this->callProtected($provider, 'callResponsesApi', [['file-123'], 'test prompt', 'gpt-4o']);
    }

    public function testCallResponsesApiThrowsOn429WithRateLimit(): void
    {
        $provider = $this->createProvider();
        $response = $this->createMockResponse(429, [
            'error' => ['type' => 'rate_limit', 'message' => 'rate limit exceeded'],
        ]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $reflection = new \ReflectionClass($provider);
        $prop = $reflection->getProperty('httpClient');
        $prop->setAccessible(true);
        $prop->setValue($provider, $httpClient);

        $this->expectException(VisionApiException::class);
        $this->expectExceptionMessage('rate limit');
        $this->callProtected($provider, 'callResponsesApi', [['file-123'], 'test prompt', 'gpt-4o']);
    }

    public function testCallResponsesApiThrowsOnNon200(): void
    {
        $provider = $this->createProvider();
        $response = $this->createMockResponse(500, ['error' => ['message' => 'server error']]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $reflection = new \ReflectionClass($provider);
        $prop = $reflection->getProperty('httpClient');
        $prop->setAccessible(true);
        $prop->setValue($provider, $httpClient);

        $this->expectException(VisionApiException::class);
        $this->callProtected($provider, 'callResponsesApi', [['file-123'], 'test prompt', 'gpt-4o']);
    }

    public function testCallResponsesApiThrowsOnTransportError(): void
    {
        $provider = $this->createProvider();

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willThrowException($this->createMock(TransportExceptionInterface::class));

        $reflection = new \ReflectionClass($provider);
        $prop = $reflection->getProperty('httpClient');
        $prop->setAccessible(true);
        $prop->setValue($provider, $httpClient);

        $this->expectException(VisionApiException::class);
        $this->callProtected($provider, 'callResponsesApi', [['file-123'], 'test prompt', 'gpt-4o']);
    }
}
