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

    public function testExtractFromValidTextFile(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_text_' . Uuid::v7() . '.txt';
        $content = "This is a test text file.\nIt has multiple lines.\nAnd some numbers: 123.";
        file_put_contents($tempFile, $content);

        try {
            $result = $this->extractor->extractFromText($tempFile);

            $this->assertArrayHasKey('text', $result);
            $this->assertArrayHasKey('pages', $result);
            $this->assertArrayHasKey('pageCount', $result);
            $this->assertArrayHasKey('hasExtractableText', $result);
            $this->assertArrayHasKey('extractionMethod', $result);
            $this->assertSame('text_plain', $result['extractionMethod']);
            $this->assertTrue($result['hasExtractableText']);
            $this->assertSame($content, $result['text']);
            $this->assertCount(1, $result['pages']);
            $this->assertSame($content, $result['pages'][0]['text']);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testExtractFromTextFileWithHindiUnicode(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_hindi_' . Uuid::v7() . '.txt';
        $content = "भारत का राष्ट्रीय पशु बाघ है।\nThis is mixed Hindi and English.\nप्रकाश संश्लेषण में plants सूर्य के प्रकाश का उपयोग करते हैं।";
        file_put_contents($tempFile, $content);

        try {
            $result = $this->extractor->extractFromText($tempFile);

            $this->assertArrayHasKey('text', $result);
            $this->assertSame('text_plain', $result['extractionMethod']);
            $this->assertTrue($result['hasExtractableText']);
            $this->assertSame($content, $result['text']);
            $this->assertStringContainsString('भारत', $result['text']);
            $this->assertStringContainsString('plants', $result['text']);
            $this->assertStringContainsString('प्रकाश', $result['text']);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testExtractFromEmptyTextFile(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_empty_' . Uuid::v7() . '.txt';
        file_put_contents($tempFile, '');

        try {
            $result = $this->extractor->extractFromText($tempFile);

            $this->assertArrayHasKey('text', $result);
            $this->assertArrayHasKey('pages', $result);
            $this->assertArrayHasKey('pageCount', $result);
            $this->assertArrayHasKey('hasExtractableText', $result);
            $this->assertArrayHasKey('extractionMethod', $result);
            $this->assertSame('text_plain', $result['extractionMethod']);
            $this->assertFalse($result['hasExtractableText']);
            $this->assertSame('', $result['text']);
            $this->assertCount(1, $result['pages']);
            $this->assertSame('', $result['pages'][0]['text']);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testExtractThrowsOnUnsupportedType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->extractor->extract('/fake/path.xyz', 'application/unsupported');
    }
}