<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis\Processing;

use App\Exception\VisionApiException;
use App\Service\Analysis\Processing\OpenAiVisionProvider;
use App\Service\Analysis\Processing\PdfPageRendererInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class OpenAiVisionProviderTest extends TestCase
{
    private HttpClientInterface $httpClient;
    private PdfPageRendererInterface $pdfRenderer;
    private OpenAiVisionProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->pdfRenderer = $this->createMock(PdfPageRendererInterface::class);
        
        $this->provider = new OpenAiVisionProvider(
            $this->httpClient,
            'test-api-key',
            'gpt-4o',
            new NullLogger(),
            sys_get_temp_dir(),
            $this->pdfRenderer
        );
    }

    public function testSupportsMimeType(): void
    {
        $this->assertTrue($this->provider->supportsMimeType('application/pdf'));
        $this->assertTrue($this->provider->supportsMimeType('image/jpeg'));
        $this->assertTrue($this->provider->supportsMimeType('image/png'));
        $this->assertFalse($this->provider->supportsMimeType('text/plain'));
        $this->assertFalse($this->provider->supportsMimeType('application/msword'));
    }

    public function testParseVisionResponseWithValidJson(): void
    {
        $response = [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'pageNumber' => 1,
                            'text' => 'Question 1\nAnswer: Paris',
                            'questions' => [
                                [
                                    'questionNumber' => '1',
                                    'questionText' => 'What is the capital of France?',
                                    'answerText' => 'Paris',
                                    'marks' => 5,
                                ],
                            ],
                            'metadata' => [
                                'studentName' => 'John Doe',
                                'rollNumber' => '123',
                                'subject' => 'Geography',
                                'examTitle' => 'Quiz 1',
                                'class' => '10',
                                'section' => 'A',
                                'totalMarks' => 20,
                            ],
                            'confidence' => 0.9,
                            'language' => 'en',
                        ]),
                    ],
                ],
            ],
        ];

        $result = $this->provider->parseVisionResponse($response, 1);

        $this->assertSame(1, $result['pageNumber']);
        $this->assertSame('Question 1\nAnswer: Paris', $result['text']);
        $this->assertCount(1, $result['questions']);
        $this->assertSame('1', $result['questions'][0]['questionNumber']);
        $this->assertSame('What is the capital of France?', $result['questions'][0]['questionText']);
        $this->assertSame('Paris', $result['questions'][0]['answerText']);
        $this->assertSame(5, $result['questions'][0]['marks']);
        $this->assertSame('John Doe', $result['metadata']['studentName']);
        $this->assertSame(0.9, $result['confidence']);
        $this->assertSame('en', $result['language']);
    }

    public function testParseVisionResponseWithJsonInCodeFence(): void
    {
        $response = [
            'choices' => [
                [
                    'message' => [
                        'content' => '```json
{
  "pageNumber": 1,
  "text": "Question 1\nAnswer: 5",
  "questions": [
    {
      "questionNumber": "1",
      "questionText": "Solve 2+3",
      "answerText": "5",
      "marks": 3
    }
  ],
  "metadata": {},
  "confidence": 0.85,
  "language": "en"
}
```',
                    ],
                ],
            ],
        ];

        $result = $this->provider->parseVisionResponse($response, 1);

        $this->assertSame(1, $result['pageNumber']);
        $this->assertCount(1, $result['questions']);
        $this->assertSame('1', $result['questions'][0]['questionNumber']);
        $this->assertSame(0.85, $result['confidence']);
    }

    public function testParseVisionResponseWithNullQuestionNumber(): void
    {
        $response = [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'pageNumber' => 1,
                            'text' => 'Answer: Paris',
                            'questions' => [
                                [
                                    'questionNumber' => null,
                                    'questionText' => null,
                                    'answerText' => 'Paris',
                                    'marks' => null,
                                ],
                            ],
                            'metadata' => [],
                            'confidence' => 0.7,
                            'language' => 'en',
                        ]),
                    ],
                ],
            ],
        ];

        $result = $this->provider->parseVisionResponse($response, 1);

        $this->assertSame(1, $result['pageNumber']);
        $this->assertSame('', $result['questions'][0]['questionNumber']);
        $this->assertSame('', $result['questions'][0]['questionText']);
        $this->assertNull($result['questions'][0]['marks']);
    }

    public function testValidateVisionResultUsable(): void
    {
        $result = [
            'text' => 'Question 1\nAnswer: The mitochondria is the powerhouse of the cell.',
            'questions' => [
                [
                    'questionNumber' => '1',
                    'answerText' => 'The mitochondria is the powerhouse of the cell.',
                ],
            ],
        ];

        $validation = $this->provider->validateVisionResult($result);

        $this->assertTrue($validation['usable']);
    }

    public function testValidateVisionResultEmptyText(): void
    {
        $result = [
            'text' => '',
            'questions' => [],
        ];

        $validation = $this->provider->validateVisionResult($result);

        $this->assertFalse($validation['usable']);
        $this->assertStringContainsString('too short', $validation['reason']);
    }

    public function testValidateVisionResultOnlyPlaceholders(): void
    {
        $result = [
            'text' => 'Question 1 (5 marks)\nQuestion 2 (3 marks)',
            'questions' => [
                [
                    'questionNumber' => '1',
                    'answerText' => '(5 marks)',
                ],
                [
                    'questionNumber' => '2',
                    'answerText' => 'Answer:',
                ],
            ],
        ];

        $validation = $this->provider->validateVisionResult($result);

        $this->assertFalse($validation['usable']);
        $this->assertStringContainsString('placeholder', $validation['reason']);
    }

    public function testIsPlaceholderText(): void
    {
        $this->assertTrue($this->provider->isPlaceholderText('(5 marks)'));
        $this->assertTrue($this->provider->isPlaceholderText('Question 1'));
        $this->assertTrue($this->provider->isPlaceholderText('Answer:'));
        $this->assertTrue($this->provider->isPlaceholderText('Working:'));
        $this->assertTrue($this->provider->isPlaceholderText('(5 अंक)'));
        $this->assertTrue($this->provider->isPlaceholderText('उत्तर:'));
        $this->assertFalse($this->provider->isPlaceholderText('The answer is Paris'));
        $this->assertFalse($this->provider->isPlaceholderText('x = 5'));
        $this->assertFalse($this->provider->isPlaceholderText('Photosynthesis is the process'));
    }

    public function testNormalizeVisionResult(): void
    {
        $pageResults = [
            [
                'pageNumber' => 1,
                'text' => 'Question 1\nAnswer: Paris',
                'questions' => [
                    [
                        'questionNumber' => '1',
                        'questionText' => 'What is the capital of France?',
                        'answerText' => 'Paris',
                        'marks' => 5,
                    ],
                ],
                'metadata' => [
                    'studentName' => 'John Doe',
                    'rollNumber' => '123',
                ],
                'confidence' => 0.9,
                'language' => 'en',
            ],
            [
                'pageNumber' => 2,
                'text' => 'Question 2\nAnswer: London',
                'questions' => [
                    [
                        'questionNumber' => '2',
                        'questionText' => 'What is the capital of UK?',
                        'answerText' => 'London',
                        'marks' => 5,
                    ],
                ],
                'metadata' => [
                    'subject' => 'Geography',
                ],
                'confidence' => 0.8,
                'language' => 'en',
            ],
        ];

        $ocrMetrics = [
            'pagesDetected' => 2,
            'pagesProcessed' => 2,
            'pagesSkipped' => 0,
            'ocrPagesProcessed' => 2,
            'ocrCacheHits' => 0,
            'ocrAttempts' => 2,
            'ocrRetryCount' => 0,
        ];

        $result = $this->provider->normalizeVisionResult($pageResults, $ocrMetrics);

        $this->assertCount(2, $result['pages']);
        $this->assertSame('John Doe', $result['metadata']['studentName']);
        $this->assertSame('123', $result['metadata']['rollNumber']);
        $this->assertSame('Geography', $result['metadata']['subject']);
        $this->assertSame(0.85, $result['confidence']);
        $this->assertSame('OPENAI_VISION', $result['extractionMethod']);
        $this->assertCount(2, $result['questions']);
    }

    private function createValidVisionResponse(int $pageNumber, string $text = 'Question 1 Answer: Paris'): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'pageNumber' => $pageNumber,
                            'text' => $text,
                            'questions' => [
                                [
                                    'questionNumber' => '1',
                                    'questionText' => 'What is the capital of France?',
                                    'answerText' => 'Paris',
                                    'marks' => 5,
                                    'evaluation' => [
                                        'resultStatus' => 'CORRECT',
                                        'marksAwarded' => 5,
                                        'maxMarks' => 5,
                                        'answerQuality' => 0.9,
                                        'reasoningQuality' => 0.85,
                                        'confidence' => 0.88,
                                        'errorType' => 'NONE',
                                        'difficulty' => 'EASY',
                                    ],
                                    'questionKnownFromPaper' => true,
                                ],
                            ],
                            'metadata' => [],
                            'confidence' => 0.9,
                            'language' => 'en',
                        ]),
                    ],
                ],
            ],
        ];
    }

    private function createTempImages(int $count): array
    {
        $paths = [];
        for ($i = 0; $i < $count; $i++) {
            $path = sys_get_temp_dir() . '/test_vision_page_' . $i . '_' . uniqid() . '.jpg';
            file_put_contents($path, 'fake-image-content');
            $paths[] = $path;
        }
        return $paths;
    }

    public function testProcessPdfProcessesPagesOneAtATimeWithSingleImagePerRequest(): void
    {
        $tempPaths = $this->createTempImages(3);

        $renderer = $this->createMock(PdfPageRendererInterface::class);
        $renderer->method('isAvailable')->willReturn(true);
        $renderer->method('getPageCount')->willReturn(3);
        $renderer->expects($this->exactly(3))->method('renderPage')
            ->willReturnOnConsecutiveCalls($tempPaths[0], $tempPaths[1], $tempPaths[2]);

        $innerContent = json_encode([
            'pages' => [
                ['pageNumber' => 1, 'text' => 'Answer 1', 'questions' => [], 'metadata' => [], 'confidence' => 0.9, 'language' => 'en'],
            ],
            'metadata' => ['studentName' => 'Test', 'rollNumber' => '123', 'subject' => 'Math', 'examTitle' => 'Exam', 'class' => '10', 'section' => 'A', 'totalMarks' => 100],
            'confidence' => 0.9,
            'extractionMethod' => 'OPENAI_VISION',
        ]);

        $requestPayloads = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestPayloads, $innerContent) {
            $decoded = json_decode($options['body'] ?? '{}', true);
            $requestPayloads[] = $decoded['messages'][0]['content'] ?? [];
            $responseBody = json_encode([
                'choices' => [['message' => ['content' => $innerContent]]],
            ]);
            return new MockResponse($responseBody);
        });

        $provider = new OpenAiVisionProvider($httpClient, 'test-key', 'gpt-4o', new NullLogger(), sys_get_temp_dir(), $renderer);
        $result = $provider->processPdf('/tmp/test.pdf', 'hash123');

        $this->assertCount(3, $result['pages']);
        $this->assertCount(3, $requestPayloads);

        foreach ($requestPayloads as $payload) {
            $imageCount = 0;
            foreach ($payload as $item) {
                if ($item['type'] === 'image_url') {
                    $imageCount++;
                }
            }
            $this->assertSame(1, $imageCount, 'Each OpenAI request must contain exactly 1 image');
        }

        foreach ($tempPaths as $path) {
            $this->assertFileDoesNotExist($path, 'Temp image should be cleaned up');
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testProcessPdfAggregatesResultsFromAllPages(): void
    {
        $tempPaths = $this->createTempImages(3);

        $renderer = $this->createMock(PdfPageRendererInterface::class);
        $renderer->method('isAvailable')->willReturn(true);
        $renderer->method('getPageCount')->willReturn(3);
        $renderer->expects($this->exactly(3))->method('renderPage')
            ->willReturnOnConsecutiveCalls($tempPaths[0], $tempPaths[1], $tempPaths[2]);

        $responses = [
            ['pageNumber' => 1, 'text' => 'Answer 1 with detailed working', 'questions' => [['questionNumber' => '1', 'answerText' => 'Answer 1', 'marks' => 5, 'evaluation' => ['resultStatus' => 'CORRECT', 'marksAwarded' => 5, 'maxMarks' => 5]]], 'metadata' => [], 'confidence' => 0.9, 'language' => 'en'],
            ['pageNumber' => 2, 'text' => 'Answer 2 with detailed working', 'questions' => [['questionNumber' => '2', 'answerText' => 'Answer 2', 'marks' => 3, 'evaluation' => ['resultStatus' => 'CORRECT', 'marksAwarded' => 3, 'maxMarks' => 3]]], 'metadata' => [], 'confidence' => 0.8, 'language' => 'en'],
            ['pageNumber' => 3, 'text' => 'Answer 3 with detailed working', 'questions' => [['questionNumber' => '3', 'answerText' => 'Answer 3', 'marks' => 2, 'evaluation' => ['resultStatus' => 'PARTIALLY_CORRECT', 'marksAwarded' => 1, 'maxMarks' => 2]]], 'metadata' => [], 'confidence' => 0.7, 'language' => 'en'],
        ];

        $callIndex = 0;
        $httpClient = new MockHttpClient(function () use (&$responses, &$callIndex) {
            $data = $responses[$callIndex];
            $callIndex++;
            $innerContent = json_encode([
                'pages' => [$data],
                'metadata' => ['studentName' => 'Test', 'rollNumber' => '123', 'subject' => 'Math', 'examTitle' => 'Exam', 'class' => '10', 'section' => 'A', 'totalMarks' => 100],
                'confidence' => $data['confidence'],
            ]);
            $responseBody = json_encode([
                'choices' => [['message' => ['content' => $innerContent]]],
            ]);
            return new MockResponse($responseBody);
        });

        $provider = new OpenAiVisionProvider($httpClient, 'test-key', 'gpt-4o', new NullLogger(), sys_get_temp_dir(), $renderer);
        $result = $provider->processPdf('/tmp/test.pdf', 'hash123');

        $this->assertCount(3, $result['pages']);
        $this->assertSame(1, $result['pages'][0]['pageNumber']);
        $this->assertSame(2, $result['pages'][1]['pageNumber']);
        $this->assertSame(3, $result['pages'][2]['pageNumber']);
        $this->assertSame('Answer 1 with detailed working', $result['pages'][0]['text']);
        $this->assertSame('Answer 2 with detailed working', $result['pages'][1]['text']);
        $this->assertSame('Answer 3 with detailed working', $result['pages'][2]['text']);
        $this->assertSame('Test', $result['metadata']['studentName']);

        foreach ($tempPaths as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testProcessPdfPageFailureDoesNotCorruptPreviousResults(): void
    {
        $tempPaths = $this->createTempImages(3);

        $renderer = $this->createMock(PdfPageRendererInterface::class);
        $renderer->method('isAvailable')->willReturn(true);
        $renderer->method('getPageCount')->willReturn(3);
        $renderer->expects($this->exactly(3))->method('renderPage')
            ->willReturnOnConsecutiveCalls($tempPaths[0], $tempPaths[1], $tempPaths[2]);

        $callIndex = 0;
        $httpClient = new MockHttpClient(function () use (&$callIndex) {
            $callIndex++;
            if ($callIndex === 2) {
                return new MockResponse('{"error":{"type":"server_error","message":"internal error"}}', ['http_code' => 500]);
            }
            $innerContent = json_encode([
                'pages' => [
                    ['pageNumber' => $callIndex, 'text' => "Answer {$callIndex} with detailed working", 'questions' => [], 'metadata' => [], 'confidence' => 0.9, 'language' => 'en'],
                ],
                'metadata' => ['studentName' => 'Test', 'rollNumber' => '123'],
                'confidence' => 0.9,
            ]);
            $responseBody = json_encode([
                'choices' => [['message' => ['content' => $innerContent]]],
            ]);
            return new MockResponse($responseBody);
        });

        $provider = new OpenAiVisionProvider($httpClient, 'test-key', 'gpt-4o', new NullLogger(), sys_get_temp_dir(), $renderer);
        $result = $provider->processPdf('/tmp/test.pdf', 'hash123');

        $this->assertCount(3, $result['pages']);
        $this->assertSame('Answer 1 with detailed working', $result['pages'][0]['text']);
        $this->assertSame('', $result['pages'][1]['text']);
        $this->assertSame('Answer 3 with detailed working', $result['pages'][2]['text']);

        foreach ($tempPaths as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testProcessPdfInsufficientQuotaStopsFurtherRequests(): void
    {
        $tempPaths = $this->createTempImages(3);

        $renderer = $this->createMock(PdfPageRendererInterface::class);
        $renderer->method('isAvailable')->willReturn(true);
        $renderer->method('getPageCount')->willReturn(3);
        $renderer->method('renderPage')->willReturnOnConsecutiveCalls($tempPaths[0], $tempPaths[1], $tempPaths[2]);

        $requestCount = 0;
        $httpClient = new MockHttpClient(function () use (&$requestCount) {
            $requestCount++;
            if ($requestCount === 1) {
                return new MockResponse('{"error":{"type":"insufficient_quota","message":"quota exceeded"}}', ['http_code' => 429]);
            }
            $innerContent = json_encode(['pages' => [], 'metadata' => [], 'confidence' => 0.5]);
            $responseBody = json_encode([
                'choices' => [['message' => ['content' => $innerContent]]],
            ]);
            return new MockResponse($responseBody);
        });

        $provider = new OpenAiVisionProvider($httpClient, 'test-key', 'gpt-4o', new NullLogger(), sys_get_temp_dir(), $renderer);
        $result = $provider->processPdf('/tmp/test.pdf', 'hash123');

        $this->assertSame(1, $requestCount, 'Should stop after first insufficient_quota error');
        $this->assertSame(3, count($result['pages']), 'All pages should have entries (empty for skipped)');
        $this->assertSame(0, count($result['pages'][0]['questions'] ?? []), 'Page 1 should have empty questions');

        foreach ($tempPaths as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testProcessPdfThrowsWhenRendererUnavailable(): void
    {
        $this->expectException(\App\Exception\PdfRendererUnavailableException::class);

        $renderer = $this->createMock(PdfPageRendererInterface::class);
        $renderer->method('isAvailable')->willReturn(false);

        $httpClient = new MockHttpClient();

        $provider = new OpenAiVisionProvider($httpClient, 'test-key', 'gpt-4o', new NullLogger(), sys_get_temp_dir(), $renderer);
        $provider->processPdf('/tmp/test.pdf', 'hash123');
    }

    public function testProcessPdfCleansUpTempFilesAfterProcessing(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_jpeg_cleanup_' . uniqid() . '.jpg';
        file_put_contents($tempFile, 'fake-jpeg-content');

        $renderer = $this->createMock(PdfPageRendererInterface::class);
        $renderer->method('isAvailable')->willReturn(true);
        $renderer->method('getPageCount')->willReturn(1);
        $renderer->method('renderPage')->willReturn($tempFile);

        $httpClient = new MockHttpClient([
            new MockResponse(json_encode([
                'choices' => [['message' => ['content' => json_encode([
                    'pages' => [['pageNumber' => 1, 'text' => 'Answer 1 with detailed working', 'questions' => [], 'metadata' => [], 'confidence' => 0.9, 'language' => 'en']],
                    'metadata' => ['studentName' => 'Test'],
                    'confidence' => 0.9,
                ])]]],
            ])),
        ]);

        $provider = new OpenAiVisionProvider($httpClient, 'test-key', 'gpt-4o', new NullLogger(), sys_get_temp_dir(), $renderer);
        $provider->processPdf('/tmp/test.pdf', 'hash123');

        $this->assertFileDoesNotExist($tempFile, 'Temp image file should be cleaned up after processing');
    }
}