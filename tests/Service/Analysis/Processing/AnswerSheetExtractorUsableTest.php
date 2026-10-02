<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis\Processing;

use App\DTO\Analysis\ExtractedAnswerSheetMetadata;
use App\DTO\Analysis\ExtractedAnswer;
use App\Service\Analysis\Processing\AnswerSheetExtractor;
use App\Service\Analysis\Processing\ProvenanceValue;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AnswerSheetExtractorUsableTest extends KernelTestCase
{
    private AnswerSheetExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new AnswerSheetExtractor(new NullLogger());
    }

    public function testExtractionUsableWithMeaningfulAnswers(): void
    {
        $extractionResult = [
            'metadata' => new ExtractedAnswerSheetMetadata(),
            'answers' => [
                new ExtractedAnswer('1', 'This is a real answer', 1, 0.8),
                new ExtractedAnswer('2', 'Another answer', 1, 0.8),
            ],
            'extractionResult' => 'SUCCESS',
            'extractionConfidence' => 0.8,
        ];

        $this->assertTrue($this->extractor->isExtractionUsable($extractionResult));
    }

    public function testExtractionUsableWithMetadataAndAnswers(): void
    {
        $metadata = new ExtractedAnswerSheetMetadata(
            studentName: ProvenanceValue::extracted('John Doe', 0.9),
            rollNumber: ProvenanceValue::extracted('123', 0.9),
            subject: ProvenanceValue::extracted('Math', 0.9),
        );

        $extractionResult = [
            'metadata' => $metadata,
            'answers' => [
                new ExtractedAnswer('1', '', 1, 0.8), // empty answer but has metadata
            ],
            'extractionResult' => 'PARTIAL_METADATA_ONLY',
            'extractionConfidence' => 0.5,
        ];

        $this->assertTrue($this->extractor->isExtractionUsable($extractionResult));
    }

    public function testExtractionNotUsableWithOnlyPlaceholders(): void
    {
        $extractionResult = [
            'metadata' => new ExtractedAnswerSheetMetadata(),
            'answers' => [
                new ExtractedAnswer('1', '(5 marks)', 1, 0.8),
                new ExtractedAnswer('2', '(5 marks)', 1, 0.8),
                new ExtractedAnswer('3', '(5 marks)', 1, 0.8),
            ],
            'extractionResult' => 'PARTIAL_ANSWERS_ONLY',
            'extractionConfidence' => 0.3,
        ];

        $this->assertFalse($this->extractor->isExtractionUsable($extractionResult));
    }

    public function testExtractionNotUsableWithEmptyAnswers(): void
    {
        $extractionResult = [
            'metadata' => new ExtractedAnswerSheetMetadata(),
            'answers' => [
                new ExtractedAnswer('1', '', 1, 0.8),
                new ExtractedAnswer('2', '', 1, 0.8),
            ],
            'extractionResult' => 'PARTIAL_ANSWERS_ONLY',
            'extractionConfidence' => 0.2,
        ];

        $this->assertFalse($this->extractor->isExtractionUsable($extractionResult));
    }

    public function testExtractionNotUsableWithQuestionHeadersOnly(): void
    {
        $extractionResult = [
            'metadata' => new ExtractedAnswerSheetMetadata(),
            'answers' => [
                new ExtractedAnswer('1', 'Question 1', 1, 0.8),
                new ExtractedAnswer('2', 'Question 2', 1, 0.8),
            ],
            'extractionResult' => 'PARTIAL_ANSWERS_ONLY',
            'extractionConfidence' => 0.2,
        ];

        $this->assertFalse($this->extractor->isExtractionUsable($extractionResult));
    }

    public function testExtractionNotUsableWithAnswerColonOnly(): void
    {
        $extractionResult = [
            'metadata' => new ExtractedAnswerSheetMetadata(),
            'answers' => [
                new ExtractedAnswer('1', 'Answer:', 1, 0.8),
                new ExtractedAnswer('2', 'Working:', 1, 0.8),
            ],
            'extractionResult' => 'PARTIAL_ANSWERS_ONLY',
            'extractionConfidence' => 0.2,
        ];

        $this->assertFalse($this->extractor->isExtractionUsable($extractionResult));
    }

    public function testExtractionNotUsableWithNoMetadataOrAnswers(): void
    {
        $extractionResult = [
            'metadata' => new ExtractedAnswerSheetMetadata(),
            'answers' => [],
            'extractionResult' => 'NO_TEXT_DETECTED',
            'extractionConfidence' => 0.0,
        ];

        $this->assertFalse($this->extractor->isExtractionUsable($extractionResult));
    }

    public function testExtractionUsableWithSomeMeaningfulAndSomeEmpty(): void
    {
        $extractionResult = [
            'metadata' => new ExtractedAnswerSheetMetadata(),
            'answers' => [
                new ExtractedAnswer('1', '', 1, 0.8),
                new ExtractedAnswer('2', 'Real answer here', 1, 0.8),
                new ExtractedAnswer('3', '(5 marks)', 1, 0.8),
            ],
            'extractionResult' => 'PARTIAL_ANSWERS_ONLY',
            'extractionConfidence' => 0.4,
        ];

        $this->assertTrue($this->extractor->isExtractionUsable($extractionResult));
    }
}