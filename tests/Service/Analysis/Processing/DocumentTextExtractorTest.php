<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis\Processing;

use App\Service\Analysis\Processing\DocumentTextExtractor;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class DocumentTextExtractorTest extends KernelTestCase
{
    private DocumentTextExtractor $extractor;

    protected function setUp(): void
    {
        $kernel = static::getContainer();
        $this->extractor = new DocumentTextExtractor(
            new NullLogger(),
            $kernel->getParameter('kernel.project_dir')
        );
    }

public function testExtractFromValidPdf(): void
    {
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        $fixturePath = realpath($projectDir . '/tests/fixtures/analysis/answer-sheet.pdf');
        $this->assertFileExists($fixturePath);

        $result = $this->extractor->extractFromPdf($fixturePath);

        $this->assertArrayHasKey('text', $result);
        $this->assertArrayHasKey('pages', $result);
        $this->assertArrayHasKey('pageCount', $result);
        $this->assertArrayHasKey('hasExtractableText', $result);
        $this->assertArrayHasKey('extractionMethod', $result);
        $this->assertSame('pdf_text', $result['extractionMethod']);
        $this->assertTrue($result['hasExtractableText']);
        $this->assertNotEmpty($result['text']);

        $this->assertStringContainsString('Aarav', $result['text']);
        $this->assertStringContainsString('Kumar', $result['text']);
        $this->assertStringContainsString('Science', $result['text']);
        $this->assertStringContainsString('Question 1', $result['text']);
        $this->assertStringContainsString('Question 6', $result['text']);
    }

    public function testExtractFromImageReturnsPlaceholder(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_image_' . Uuid::v7() . '.jpg';
        file_put_contents($tempFile, 'fake image content');

        try {
            $result = $this->extractor->extractFromImage($tempFile, 'image/jpeg');

            $this->assertArrayHasKey('text', $result);
            $this->assertArrayHasKey('pages', $result);
            $this->assertArrayHasKey('pageCount', $result);
            $this->assertSame(1, $result['pageCount']);
            $this->assertStringContainsString('OCR not implemented', $result['pages'][0]['text']);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testExtractThrowsOnUnsupportedType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->extractor->extract('/fake/path.txt', 'text/plain');
    }
}