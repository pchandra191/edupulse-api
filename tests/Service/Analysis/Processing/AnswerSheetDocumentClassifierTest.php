<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis\Processing;

use App\DTO\Analysis\DocumentClassificationResult;
use App\Enum\Analysis\DocumentType;
use App\Service\Analysis\Processing\AnswerSheetDocumentClassifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AnswerSheetDocumentClassifierTest extends TestCase
{
    private AnswerSheetDocumentClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new AnswerSheetDocumentClassifier(new NullLogger());
    }

    public function testValidTypedAnswerSheet(): void
    {
        $text = "Question 1\nWhat is the capital of France?\nAnswer: Paris is the capital city of France.\n\nQuestion 2\nExplain photosynthesis.\nAnswer: Photosynthesis is the process by which plants convert sunlight into energy.";
        
        $result = $this->classifier->classifyFromText($text, 1);
        
        $this->assertSame(DocumentType::AnswerSheet, $result->documentType);
        $this->assertSame('VALID', $result->status);
        $this->assertGreaterThanOrEqual(0.5, $result->confidence);
        $this->assertContains('question_numbering', $result->signals);
        $this->assertContains('answer_content', $result->signals);
    }

    public function testEmptyDocument(): void
    {
        $text = "";
        
        $result = $this->classifier->classifyFromText($text, 1);
        
        $this->assertSame(DocumentType::Unknown, $result->documentType);
        $this->assertSame('REJECTED', $result->status);
        $this->assertStringContainsString('doesn\'t appear to be a student answer sheet', $result->reason);
    }

    public function testScannedDocument(): void
    {
        $text = "\x00\x01\x02\x03"; // Binary garbage
        
        $result = $this->classifier->classifyFromText($text, 1);
        
        $this->assertSame(DocumentType::Unknown, $result->documentType);
        $this->assertSame('REJECTED', $result->status);
    }

    public function testHeaderOnlyDocument(): void
    {
        $text = "School Name\nClass: 10\nSection: A\nRoll Number:\nSubject: Mathematics\nDate: 2024-01-15\n\n";
        
        $result = $this->classifier->classifyFromText($text, 1);
        
        $this->assertSame(DocumentType::Unknown, $result->documentType);
        $this->assertSame('REJECTED', $result->status);
        $this->assertStringContainsString('doesn\'t appear to be a student answer sheet', $result->reason);
    }

    public function testMarksOnlyDocument(): void
    {
        $text = "Question 1 (5 marks)\nQuestion 2 (3 marks)\nQuestion 3 (10 marks)\nTotal: 20 marks";
        
        $result = $this->classifier->classifyFromText($text, 1);
        
        $this->assertSame(DocumentType::QuestionPaper, $result->documentType);
        $this->assertSame('REJECTED', $result->status);
        $this->assertStringContainsString('question paper', $result->reason);
    }

    public function testQuestionPaperDetected(): void
    {
        $text = "Instructions: Read carefully.\nTime allowed: 3 hours\nMaximum marks: 100\n\nQuestion 1 (5 marks)\nQuestion 2 (3 marks)\nQuestion 3 (10 marks)";
        
        $result = $this->classifier->classifyFromText($text, 1);
        
        $this->assertSame(DocumentType::QuestionPaper, $result->documentType);
        $this->assertSame('REJECTED', $result->status);
        $this->assertStringContainsString('question paper', $result->reason);
    }

    public function testMixedMeaningfulAndInsignificant(): void
    {
        $text = "School Header\nPage 1\n\nQuestion 1 (5 marks)\nAnswer: The mitochondria is the powerhouse of the cell.\n\nQuestion 2 (3 marks)\nAnswer: Photosynthesis converts CO2 and water into glucose.\n\nFooter text";
        
        $result = $this->classifier->classifyFromText($text, 1);
        
        $this->assertSame(DocumentType::AnswerSheet, $result->documentType);
        $this->assertSame('VALID', $result->status);
        $this->assertGreaterThanOrEqual(0.5, $result->confidence);
        $this->assertContains('question_numbering', $result->signals);
        $this->assertContains('answer_content', $result->signals);
        $this->assertContains('marks_indicator', $result->signals);
    }

    public function testMathWorking(): void
    {
        $text = "Question 1 (5 marks)\nSolve for x: 2x + 5 = 15\nAnswer: Therefore 2x = 10, so x = 5";
        
        $result = $this->classifier->classifyFromText($text, 1);
        
        $this->assertSame(DocumentType::AnswerSheet, $result->documentType);
        $this->assertSame('VALID', $result->status);
        $this->assertContains('math_working', $result->signals);
    }

    public function testMCQResponse(): void
    {
        $text = "Question 1 (2 marks)\nAnswer: B\n\nQuestion 2 (2 marks)\nAnswer: A";
        
        $result = $this->classifier->classifyFromText($text, 1);
        
        $this->assertSame(DocumentType::AnswerSheet, $result->documentType);
        $this->assertSame('VALID', $result->status);
        $this->assertContains('mcq_response', $result->signals);
    }

    public function testMultiPageAnswerSheet(): void
    {
        $text = "Question 1 (5 marks)\nAnswer: Paris is the capital.\n\nQuestion 2 (3 marks)\nAnswer: Photosynthesis process.";
        
        $result = $this->classifier->classifyFromText($text, 2);
        
        $this->assertSame(DocumentType::AnswerSheet, $result->documentType);
        $this->assertSame('VALID', $result->status);
    }

    public function testHindiContent(): void
    {
        $text = "प्रश्न 1 (5 अंक)\nउत्तर: प्रकाश संश्लेषण एक प्रक्रिया है।\n\nप्रश्न 2 (3 अंक)\nउत्तर: माइटोकॉन्ड्रिया कोशिका का पावरहाउस है।";
        
        $result = $this->classifier->classifyFromText($text, 1);
        
        $this->assertSame(DocumentType::AnswerSheet, $result->documentType);
        $this->assertSame('VALID', $result->status);
    }
}