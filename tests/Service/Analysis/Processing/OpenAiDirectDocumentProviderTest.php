<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis\Processing;

use App\DTO\Analysis\Direct\CurrentExamAnalysis;
use App\DTO\Analysis\Direct\DirectExtractionResult;
use App\DTO\Analysis\ExtractedAnswerSheetMetadata;
use App\Exception\VisionApiException;
use App\Service\Analysis\AI\Prompt\ExamAnalysisPromptBuilder;
use App\Service\Analysis\Processing\OpenAiDirectDocumentProvider;
use App\Service\Analysis\Processing\OpenAiSanitizer;
use App\Service\Analysis\Settings\AnalysisSettings;
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

        $analysisSettings = new AnalysisSettings([
            'upload' => [
                'max_total_pages' => 50,
                'max_file_size' => 26214400,
                'max_files' => 100,
            ],
            'direct_ai' => [
                'model' => $extractionModel,
                'pdf_detail' => $pdfDetail,
                'max_pdf_pages' => 50,
                'max_output_tokens' => 8000,
            ],
            'pricing' => [
                'gpt-5.4-mini' => ['input' => 0.15, 'output' => 0.60],
                'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
            ],
        ]);

        return new OpenAiDirectDocumentProvider(
            $this->createMock(HttpClientInterface::class),
            'test-api-key',
            $extractionModel,
            $analysisModel,
            $pdfDetail,
            $this->createMock(LoggerInterface::class),
            '/tmp',
            new ExamAnalysisPromptBuilder(),
            $analysisSettings,
            new OpenAiSanitizer(),
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

    public function testCallResponsesApiDoesNotSendTemperatureForGpt5Nano(): void
    {
        $provider = $this->createProvider('gpt-5-nano', 'gpt-5-nano');

        $capturedPayload = null ?? [];
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturnCallback(function ($method, $url, $options) use (&$capturedPayload) {
            $capturedPayload = $options['json'] ?? null;
            $response = $this->createMockResponse(200, $this->getMockResponseData());
            return $response;
        });

        $reflection = new \ReflectionClass($provider);
        $prop = $reflection->getProperty('httpClient');
        $prop->setAccessible(true);
        $prop->setValue($provider, $httpClient);

        $this->callProtected($provider, 'callResponsesApi', [['file-123'], 'test prompt', 'gpt-5-nano']);

        // Verify temperature is NOT in the payload for gpt-5-nano
        $this->assertNotNull($capturedPayload);
        $this->assertArrayNotHasKey('temperature', $capturedPayload);
        $this->assertSame('gpt-5-nano', $capturedPayload['model']);
        $this->assertArrayHasKey('input', $capturedPayload);
        $this->assertArrayHasKey('max_output_tokens', $capturedPayload);
    }

    public function testCallResponsesApiSendsTemperatureForGpt4o(): void
    {
        $provider = $this->createProvider('gpt-4o', 'gpt-4o');

        $capturedPayload = null ?? [];
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturnCallback(function ($method, $url, $options) use (&$capturedPayload) {
            $capturedPayload = $options['json'] ?? null;
            $response = $this->createMockResponse(200, $this->getMockResponseData());
            return $response;
        });

        $reflection = new \ReflectionClass($provider);
        $prop = $reflection->getProperty('httpClient');
        $prop->setAccessible(true);
        $prop->setValue($provider, $httpClient);

        $this->callProtected($provider, 'callResponsesApi', [['file-123'], 'test prompt', 'gpt-4o']);

        // Verify temperature IS in the payload for gpt-4o
        $this->assertNotNull($capturedPayload);
        $this->assertArrayHasKey('temperature', $capturedPayload);
        $this->assertSame(0.1, $capturedPayload['temperature']);
        $this->assertSame('gpt-4o', $capturedPayload['model']);
    }

private function getMockResponseData(): array
    {
        return [
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'extractedAnswers' => [],
                        'extractedQuestions' => [],
                        'extractedMetadata' => [],
                        'extraction' => [
                            'status' => 'SUCCESS',
                            'confidence' => 0.9,
                        ],
                        'questionAnalysis' => [],
                        'currentExamAnalysis' => [
                            'strengths' => [],
                            'weaknesses' => [],
                            'needsAttention' => [],
                            'conceptGaps' => [],
                            'recommendations' => [],
                            'conceptAssessments' => [],
                            'mistakes' => [],
                            'evidence' => [],
                            'performance' => ['score' => 25, 'totalMarks' => 30, 'percentage' => 83.33],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
            ],
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'total_tokens' => 150,
            ],
        ]];
    }
}
