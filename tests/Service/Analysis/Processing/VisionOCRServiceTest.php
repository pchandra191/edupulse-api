<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis\Processing;

use App\Document\OcrCache;
use App\Exception\PdfRendererUnavailableException;
use App\Repository\MongoDB\OcrCacheRepository;
use App\Service\Analysis\Processing\PdfPageRendererInterface;
use App\Service\Analysis\Processing\VisionOCRService;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use PHPUnit\Framework\TestCase;

class VisionOCRServiceTest extends TestCase
{
    private function createOcrResponse(string $jsonContent): ResponseInterface
    {
        return new MockResponse(json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => $jsonContent,
                    ],
                ],
            ],
        ]));
    }

    private function createValidOcrJson(int $pageNumber = 1): string
    {
        return json_encode([
            'pageNumber' => $pageNumber,
            'text' => 'This is a meaningful answer to the question.',
            'questions' => [
                [
                    'questionNumber' => '1',
                    'questionText' => 'What is the capital of France?',
                    'answerText' => 'The capital of France is Paris.',
                    'marks' => 5,
                ],
            ],
            'metadata' => [
                'studentName' => 'John Doe',
                'rollNumber' => '12345',
                'subject' => 'Geography',
                'examTitle' => 'Final Exam',
                'class' => '10',
                'section' => 'A',
                'totalMarks' => 30,
            ],
            'confidence' => 0.85,
            'language' => 'en',
        ]);
    }

    private function createStubCacheManager(): OcrCacheRepository
    {
        $repo = $this->createStub(OcrCacheRepository::class);
        $repo->method('findByCacheKey')->willReturn(null);
        return $repo;
    }

    private function createCompletedCacheManager(): OcrCacheRepository
    {
        $repo = $this->createStub(OcrCacheRepository::class);

        $cacheEntry = $this->createStub(OcrCache::class);
        $cacheEntry->method('isCompleted')->willReturn(true);
        $cacheEntry->method('getResult')->willReturn([
            'pageNumber' => 1,
            'text' => 'Cached answer text.',
            'questions' => [
                [
                    'questionNumber' => '1',
                    'questionText' => 'Q1',
                    'answerText' => 'Cached answer',
                    'marks' => 5,
                ],
            ],
            'metadata' => [],
            'confidence' => 0.8,
            'language' => 'en',
        ]);

        $repo->method('findByCacheKey')->willReturn($cacheEntry);
        return $repo;
    }

    public function testProcessPdfThrowsWhenRendererIsNull(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $cacheRepo = $this->createStubCacheManager();
        $logger = new NullLogger();

        $service = new VisionOCRService(
            $httpClient,
            'http://ocr.test',
            'test-key',
            'gpt-4o',
            $logger,
            '/tmp',
            $cacheRepo,
            null
        );

        $this->expectException(PdfRendererUnavailableException::class);
        $this->expectExceptionMessage('Handwriting extraction is temporarily unavailable because PDF page rendering is not configured.');

        $service->processPdf('/tmp/test.pdf', 'hash123');
    }

    public function testProcessPdfThrowsWhenRendererNotAvailable(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $cacheRepo = $this->createStubCacheManager();
        $logger = new NullLogger();

        $renderer = $this->createStub(PdfPageRendererInterface::class);
        $renderer->method('isAvailable')->willReturn(false);

        $service = new VisionOCRService(
            $httpClient,
            'http://ocr.test',
            'test-key',
            'gpt-4o',
            $logger,
            '/tmp',
            $cacheRepo,
            $renderer
        );

        $this->expectException(PdfRendererUnavailableException::class);
        $this->expectExceptionMessage('Handwriting extraction is temporarily unavailable because PDF page rendering is not configured.');

        $service->processPdf('/tmp/test.pdf', 'hash123');
    }

    public function testProcessPdfDoesNotCallOcrWhenRendererUnavailable(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $cacheRepo = $this->createStubCacheManager();
        $logger = new NullLogger();

        $renderer = $this->createStub(PdfPageRendererInterface::class);
        $renderer->method('isAvailable')->willReturn(false);

        $service = new VisionOCRService(
            $httpClient,
            'http://ocr.test',
            'test-key',
            'gpt-4o',
            $logger,
            '/tmp',
            $cacheRepo,
            $renderer
        );

        try {
            $service->processPdf('/tmp/test.pdf', 'hash123');
        } catch (PdfRendererUnavailableException $e) {
            // Expected
        }
    }

    public function testProcessPdfRendersPagesAndCallsOcrWhenRendererAvailable(): void
    {
        $cacheRepo = $this->createStubCacheManager();

        $tempFile1 = sys_get_temp_dir() . '/test_page_render_1_' . uniqid() . '.png';
        $tempFile2 = sys_get_temp_dir() . '/test_page_render_2_' . uniqid() . '.png';
        file_put_contents($tempFile1, 'fake-png-content-1');
        file_put_contents($tempFile2, 'fake-png-content-2');

        $renderer = $this->createMock(PdfPageRendererInterface::class);
        $renderer->method('isAvailable')->willReturn(true);
        $renderer->method('getPageCount')->willReturn(2);
        $renderer->expects($this->exactly(2))->method('renderPage')
            ->willReturnOnConsecutiveCalls($tempFile1, $tempFile2);

        $httpClient = new MockHttpClient([
            fn() => $this->createOcrResponse($this->createValidOcrJson(1)),
            fn() => $this->createOcrResponse($this->createValidOcrJson(2)),
        ]);

        $service = new VisionOCRService(
            $httpClient,
            'http://ocr.test',
            'test-key',
            'gpt-4o',
            new NullLogger(),
            '/tmp',
            $cacheRepo,
            $renderer
        );

        $result = $service->processPdf('/tmp/test.pdf', 'hash123');

        $this->assertArrayHasKey('pages', $result);
        $this->assertArrayHasKey('extractionMethod', $result);
        $this->assertSame('VISION_OCR', $result['extractionMethod']);
        $this->assertCount(2, $result['pages']);
        $this->assertArrayHasKey('ocrMetrics', $result);

        $this->assertFileDoesNotExist($tempFile1, 'Temp file 1 should be cleaned up');
        $this->assertFileDoesNotExist($tempFile2, 'Temp file 2 should be cleaned up');
    }

    public function testProcessPageImageDoesNotCallRenderer(): void
    {
        $renderer = $this->createMock(PdfPageRendererInterface::class);
        $renderer->expects($this->never())->method('renderPage');
        $renderer->expects($this->never())->method('getPageCount');
        $renderer->expects($this->never())->method('isAvailable');

        $tempFile = sys_get_temp_dir() . '/test_image_upload.png';
        file_put_contents($tempFile, 'fake-png-content');

        $cacheRepo = $this->createStubCacheManager();

        $httpClient = new MockHttpClient([
            fn() => $this->createOcrResponse($this->createValidOcrJson(1)),
        ]);

        $service = new VisionOCRService(
            $httpClient,
            'http://ocr.test',
            'test-key',
            'gpt-4o',
            new NullLogger(),
            '/tmp',
            $cacheRepo,
            $renderer
        );

        $result = $service->processPageImage($tempFile, 1);

        $this->assertArrayHasKey('pages', $result);
        $this->assertArrayHasKey('extractionMethod', $result);
        $this->assertSame('VISION_OCR', $result['extractionMethod']);
        $this->assertCount(1, $result['pages']);
        $this->assertSame(1, $result['pages'][0]['pageNumber']);

        @unlink($tempFile);
    }

    public function testProcessPageImageRespectsCache(): void
    {
        $renderer = $this->createMock(PdfPageRendererInterface::class);
        $renderer->expects($this->never())->method('renderPage');

        $cacheRepo = $this->createCompletedCacheManager();

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $tempFile = sys_get_temp_dir() . '/test_image_cache.png';
        file_put_contents($tempFile, 'fake-png-content');

        $service = new VisionOCRService(
            $httpClient,
            'http://ocr.test',
            'test-key',
            'gpt-4o',
            new NullLogger(),
            '/tmp',
            $cacheRepo,
            $renderer
        );

        $result = $service->processPageImage($tempFile, 1, 'test_hash');

        $this->assertSame(1, $result['ocrMetrics']['ocrCacheHits']);
        $this->assertTrue($result['pages'][0]['fromCache']);

        @unlink($tempFile);
    }

    public function testProcessPdfCleansUpTempFilesOnSuccess(): void
    {
        $cacheRepo = $this->createStubCacheManager();

        $tempImage = sys_get_temp_dir() . '/test_cleanup_success.png';
        file_put_contents($tempImage, 'fake-png-content');

        $renderer = $this->createMock(PdfPageRendererInterface::class);
        $renderer->method('isAvailable')->willReturn(true);
        $renderer->method('getPageCount')->willReturn(1);
        $renderer->method('renderPage')->willReturn($tempImage);

        $httpClient = new MockHttpClient([
            fn() => $this->createOcrResponse($this->createValidOcrJson(1)),
        ]);

        $service = new VisionOCRService(
            $httpClient,
            'http://ocr.test',
            'test-key',
            'gpt-4o',
            new NullLogger(),
            '/tmp',
            $cacheRepo,
            $renderer
        );

        $service->processPdf('/tmp/test.pdf', 'hash123');

        $this->assertFileDoesNotExist($tempImage, 'Temporary image should be cleaned up after successful OCR');
    }

    public function testProcessPdfCleansUpTempFilesOnFailure(): void
    {
        $cacheRepo = $this->createStubCacheManager();

        $tempImage = sys_get_temp_dir() . '/test_cleanup_failure.png';
        file_put_contents($tempImage, 'fake-png-content');

        $renderer = $this->createMock(PdfPageRendererInterface::class);
        $renderer->method('isAvailable')->willReturn(true);
        $renderer->method('getPageCount')->willReturn(1);
        $renderer->method('renderPage')->willReturn($tempImage);

        // HTTP client returns invalid JSON (no choices)
        $httpClient = new MockHttpClient([
            fn() => new MockResponse('{}'),
        ]);

        $service = new VisionOCRService(
            $httpClient,
            'http://ocr.test',
            'test-key',
            'gpt-4o',
            new NullLogger(),
            '/tmp',
            $cacheRepo,
            $renderer
        );

        $service->processPdf('/tmp/test.pdf', 'hash123');

        $this->assertFileDoesNotExist($tempImage, 'Temporary image should be cleaned up even on OCR failure');
    }
}
