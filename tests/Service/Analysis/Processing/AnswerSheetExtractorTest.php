<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis\Processing;

use App\DTO\Analysis\ExtractedAnswerSheetMetadata;
use App\DTO\Analysis\ExtractedAnswer;
use App\DTO\Analysis\ProvenanceValue;
use App\Service\Analysis\Processing\AnswerSheetExtractor;
use App\Service\Analysis\Processing\DocumentTextExtractor;
use App\Service\Analysis\Processing\DocumentTextExtractorInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class AnswerSheetExtractorTest extends KernelTestCase
{
    private AnswerSheetExtractor $extractor;
    private DocumentTextExtractorInterface $textExtractor;

    protected function setUp(): void
    {
        $this->extractor = new AnswerSheetExtractor(new \Psr\Log\NullLogger());
        $this->textExtractor = new \App\Service\Analysis\Processing\DocumentTextExtractor(
            new \Psr\Log\NullLogger(),
            static::getContainer()->getParameter('kernel.project_dir')
        );
    }

public function testExtractFromAnswerSheetWithValidText(): void
    {
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        $fixturePath = $projectDir . '/tests/fixtures/analysis/answer-sheet.pdf';
        $this->assertFileExists($fixturePath);

        // Extract text from PDF
        $extractedData = $this->textExtractor->extractFromPdf($fixturePath);

        $this->assertTrue($extractedData['hasExtractableText']);
        
        // Extract structured data from answer sheet
        $result = $this->extractor->extractFromAnswerSheet($extractedData['pages']);

        $this->assertArrayHasKey('metadata', $result);
        $this->assertArrayHasKey('answers', $result);
        $this->assertArrayHasKey('extractionResult', $result);
        $this->assertArrayHasKey('extractionConfidence', $result);
        
        $metadata = $result['metadata'];
        $answers = $result['answers'];

        // Verify metadata extraction
        $this->assertInstanceOf(ExtractedAnswerSheetMetadata::class, $metadata);
        
        // Check student name
        $this->assertNotNull($metadata->studentName);
        $this->assertSame('Aarav Kumar', $metadata->studentName?->value);
        
        // Check roll number
        $this->assertNotNull($metadata->rollNumber);
        $this->assertSame('24', $metadata->rollNumber?->value);
        
        // Check subject
        $this->assertNotNull($metadata->subject);
        $this->assertSame('Science', $metadata->subject?->value);
        
        // Check exam title
        $this->assertNotNull($metadata->examTitle);
        $this->assertSame('Unit Test Examination', $metadata->examTitle?->value);
        
        // Check total marks
        $this->assertNotNull($metadata->totalMarks);
        $this->assertSame(30, $metadata->totalMarks?->value);
        
        // Verify answers extracted
        $this->assertIsArray($answers);
        $this->assertGreaterThanOrEqual(5, count($answers));
        
        // Verify question numbers 1-5 are present
        $questionNumbers = array_map(fn(ExtractedAnswer $a) => $a->questionNumber, $answers);
        $this->assertContains('1', $questionNumbers);
        $this->assertContains('2', $questionNumbers);
        $this->assertContains('3', $questionNumbers);
        $this->assertContains('4', $questionNumbers);
        $this->assertContains('5', $questionNumbers);
        
        // Verify answers have text
        foreach ($answers as $answer) {
            $this->assertNotEmpty($answer->answerText);
            $this->assertGreaterThan(0, $answer->confidence);
        }
        
        // Verify extraction result
        $this->assertSame('SUCCESS', $result['extractionResult']);
        $this->assertGreaterThan(0, $result['extractionConfidence']);
    }
}