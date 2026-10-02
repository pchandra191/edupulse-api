<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis\Processing;

use App\Service\Analysis\Processing\ExtractionQualityValidator;
use App\DTO\Analysis\ExtractedAnswerSheetMetadata;
use App\DTO\Analysis\ExtractedAnswer;
use App\Service\Analysis\Processing\ProvenanceValue;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class HindiExtractionTest extends KernelTestCase
{
    private ExtractionQualityValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ExtractionQualityValidator(new NullLogger());
    }

    public function testHindiMeaningfulAnswerIsUsable(): void
    {
        $extractionResult = [
            'metadata' => new ExtractedAnswerSheetMetadata(),
            'answers' => [
                new ExtractedAnswer('1', 'भारत का राष्ट्रीय पशु बाघ है।', 1, 0.8),
            ],
            'extractionResult' => 'SUCCESS',
            'extractionConfidence' => 0.8,
        ];

        $pages = [
            ['page' => 1, 'text' => 'प्रश्न 1 (5 अंक) भारत का राष्ट्रीय पशु बाघ है।'],
        ];

        $result = $this->validator->validate($extractionResult, $pages);

        $this->assertTrue($result['usable']);
        $this->assertEquals(1, $result['meaningfulAnswerCount']);
        $this->assertStringContainsString('meaningful answer', $result['reason']);
    }

    public function testMixedHindiEnglishAnswerIsUsable(): void
    {
        $extractionResult = [
            'metadata' => new ExtractedAnswerSheetMetadata(),
            'answers' => [
                new ExtractedAnswer('1', 'प्रकाश संश्लेषण में plants सूर्य के प्रकाश का उपयोग करते हैं।', 1, 0.8),
            ],
            'extractionResult' => 'SUCCESS',
            'extractionConfidence' => 0.8,
        ];

        $pages = [
            ['page' => 1, 'text' => 'प्रश्न 1 (5 अंक) प्रकाश संश्लेषण में plants सूर्य के प्रकाश का उपयोग करते हैं।'],
        ];

        $result = $this->validator->validate($extractionResult, $pages);

        $this->assertTrue($result['usable']);
        $this->assertEquals(1, $result['meaningfulAnswerCount']);
    }

    public function testHindiPlaceholderOnlyIsNotUsable(): void
    {
        $extractionResult = [
            'metadata' => new ExtractedAnswerSheetMetadata(),
            'answers' => [
                new ExtractedAnswer('1', 'प्रश्न 1', 1, 0.8),
                new ExtractedAnswer('2', 'उत्तर:', 1, 0.8),
                new ExtractedAnswer('3', '(5 अंक)', 1, 0.8),
            ],
            'extractionResult' => 'PARTIAL_ANSWERS_ONLY',
            'extractionConfidence' => 0.3,
        ];

        $pages = [
            ['page' => 1, 'text' => 'प्रश्न 1 (5 अंक) उत्तर:'],
        ];

        $result = $this->validator->validate($extractionResult, $pages);

        $this->assertFalse($result['usable']);
        $this->assertEquals(0, $result['meaningfulAnswerCount']);
        $this->assertEquals(3, $result['pageAnalysis'][0]['placeholderCount']);
    }

    public function testEnglishPlaceholderOnlyIsNotUsable(): void
    {
        $extractionResult = [
            'metadata' => new ExtractedAnswerSheetMetadata(),
            'answers' => [
                new ExtractedAnswer('1', 'Question 1', 1, 0.8),
                new ExtractedAnswer('2', 'Answer:', 1, 0.8),
                new ExtractedAnswer('3', '(5 marks)', 1, 0.8),
            ],
            'extractionResult' => 'PARTIAL_ANSWERS_ONLY',
            'extractionConfidence' => 0.3,
        ];

        $pages = [
            ['page' => 1, 'text' => 'Question 1 (5 marks) Answer:'],
        ];

        $result = $this->validator->validate($extractionResult, $pages);

        $this->assertFalse($result['usable']);
        $this->assertEquals(0, $result['meaningfulAnswerCount']);
    }

    public function testHindiQuestionWithEnglishAnswer(): void
    {
        $extractionResult = [
            'metadata' => new ExtractedAnswerSheetMetadata(),
            'answers' => [
                new ExtractedAnswer('1', 'The process uses sunlight.', 1, 0.8),
            ],
            'extractionResult' => 'SUCCESS',
            'extractionConfidence' => 0.8,
        ];

        $pages = [
            ['page' => 1, 'text' => 'प्रश्न 1 (5 अंक) प्रकाश संश्लेषण क्या है? Answer: The process uses sunlight.'],
        ];

        $result = $this->validator->validate($extractionResult, $pages);

        $this->assertTrue($result['usable']);
        $this->assertEquals(1, $result['meaningfulAnswerCount']);
    }

    public function testEmptyHindiAnswerIsNotMeaningful(): void
    {
        $extractionResult = [
            'metadata' => new ExtractedAnswerSheetMetadata(),
            'answers' => [
                new ExtractedAnswer('1', '', 1, 0.8),
            ],
            'extractionResult' => 'PARTIAL_ANSWERS_ONLY',
            'extractionConfidence' => 0.2,
        ];

        $pages = [
            ['page' => 1, 'text' => 'प्रश्न 1 (5 अंक) उत्तर:'],
        ];

        $result = $this->validator->validate($extractionResult, $pages);

        $this->assertFalse($result['usable']);
        $this->assertEquals(0, $result['meaningfulAnswerCount']);
    }

    public function testHindiMetadataWithAnswers(): void
    {
        $metadata = new ExtractedAnswerSheetMetadata(
            studentName: ProvenanceValue::extracted('अमित कुमार', 0.9),
            rollNumber: ProvenanceValue::extracted('123', 0.9),
            subject: ProvenanceValue::extracted('विज्ञान', 0.9),
            examTitle: ProvenanceValue::extracted('इकाई परीक्षा', 0.8),
        );

        $extractionResult = [
            'metadata' => $metadata,
            'answers' => [
                new ExtractedAnswer('1', '', 1, 0.8),
            ],
            'extractionResult' => 'PARTIAL_METADATA_ONLY',
            'extractionConfidence' => 0.5,
        ];

        $pages = [
            ['page' => 1, 'text' => 'छात्र नाम अमित कुमार रोल नंबर 123 विषय विज्ञान'],
        ];

        $result = $this->validator->validate($extractionResult, $pages);

        $this->assertTrue($result['usable']);
        $this->assertEquals(4, $result['metadataCount']);
    }

    public function testHindiNumbersAreNotPlaceholders(): void
    {
        $extractionResult = [
            'metadata' => new ExtractedAnswerSheetMetadata(),
            'answers' => [
                new ExtractedAnswer('1', 'उत्तर 5 है।', 1, 0.8),
            ],
            'extractionResult' => 'SUCCESS',
            'extractionConfidence' => 0.8,
        ];

        $pages = [
            ['page' => 1, 'text' => 'प्रश्न 1 (5 अंक) उत्तर 5 है।'],
        ];

        $result = $this->validator->validate($extractionResult, $pages);

        $this->assertTrue($result['usable']);
        $this->assertEquals(1, $result['meaningfulAnswerCount']);
        $this->assertEquals(0, $result['pageAnalysis'][0]['placeholderCount']);
    }

    public function testDevanagariUnicodePreserved(): void
    {
        $hindiText = 'भारत का राष्ट्रीय पशु बाघ है।';
        
        $extractionResult = [
            'metadata' => new ExtractedAnswerSheetMetadata(),
            'answers' => [
                new ExtractedAnswer('1', $hindiText, 1, 0.8),
            ],
            'extractionResult' => 'SUCCESS',
            'extractionConfidence' => 0.8,
        ];

        $pages = [
            ['page' => 1, 'text' => $hindiText],
        ];

        $result = $this->validator->validate($extractionResult, $pages);

        $this->assertTrue($result['usable']);
        // Verify the answer text is preserved in original Devanagari
        $this->assertEquals($hindiText, $extractionResult['answers'][0]->answerText);
    }
}