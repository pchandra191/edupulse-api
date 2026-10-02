<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis\Processing;

use App\Enum\Analysis\TextSufficiencyDecision;
use App\Service\Analysis\Processing\MeaningfulTextDetector;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MeaningfulTextDetectorTest extends TestCase
{
    private MeaningfulTextDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new MeaningfulTextDetector(new NullLogger());
    }

    public function testMeaningfulTypedAnswerSheet(): void
    {
        $pages = [
            ['page' => 1, 'text' => "Question 1\nWhat is the capital of France?\nAnswer: Paris is the capital city of France.\n\nQuestion 2\nExplain photosynthesis.\nAnswer: Photosynthesis is the process by which plants convert sunlight into energy."],
            ['page' => 2, 'text' => "Question 3\nSolve for x: 2x + 5 = 15\nAnswer: x = 5"],
        ];

        $result = $this->detector->analyze($pages);

        $this->assertSame(TextSufficiencyDecision::TextSufficient, $result['decision']);
        $this->assertCount(2, $result['pageAnalysis']);
        $this->assertTrue($result['pageAnalysis'][0]['hasMeaningfulContent']);
        // Page 2 has short answer, may not meet threshold
    }

    public function testEmptyPdf(): void
    {
        $pages = [
            ['page' => 1, 'text' => ''],
            ['page' => 2, 'text' => ''],
        ];

        $result = $this->detector->analyze($pages);

        $this->assertSame(TextSufficiencyDecision::VisualExtractionRequired, $result['decision']);
        $this->assertStringContainsString('No pages contain meaningful text', $result['reason']);
        $this->assertFalse($result['pageAnalysis'][0]['hasMeaningfulContent']);
        $this->assertSame('Empty text', $result['pageAnalysis'][0]['rejectionReason']);
    }

    public function testScannedPdf(): void
    {
        // Scanned PDFs typically have very little extractable text, just noise
        $pages = [
            ['page' => 1, 'text' => "\x00\x01\x02\x03"], // Binary garbage
            ['page' => 2, 'text' => ""],
            ['page' => 3, 'text' => " "],
        ];

        $result = $this->detector->analyze($pages);

        $this->assertSame(TextSufficiencyDecision::VisualExtractionRequired, $result['decision']);
        $this->assertFalse($result['pageAnalysis'][0]['hasMeaningfulContent']);
    }

    public function testHeaderOnlyPdf(): void
    {
        $pages = [
            ['page' => 1, 'text' => "School Name\nClass: 10\nSection: A\nRoll Number:\nSubject: Mathematics\nDate: 2024-01-15\n\n"],
            ['page' => 2, 'text' => "School Name\nClass: 10\nSection: A\nRoll Number:\nSubject: Mathematics\nDate: 2024-01-15\n\n"],
        ];

        $result = $this->detector->analyze($pages);

        $this->assertSame(TextSufficiencyDecision::VisualExtractionRequired, $result['decision']);
        $this->assertStringContainsString('meaningful text content', strtolower($result['reason']));
        foreach ($result['pageAnalysis'] as $page) {
            $this->assertFalse($page['hasMeaningfulContent']);
        }
    }

    public function testMarksOnlyPdf(): void
    {
        $pages = [
            ['page' => 1, 'text' => "Question 1 (5 marks)\nQuestion 2 (3 marks)\nQuestion 3 (10 marks)\nQuestion 4 (2 marks)\nTotal: 20 marks"],
            ['page' => 2, 'text' => "Question 5 (4 marks)\nQuestion 6 (6 marks)\n(2 marks)"],
        ];

        $result = $this->detector->analyze($pages);

        $this->assertSame(TextSufficiencyDecision::VisualExtractionRequired, $result['decision']);
        $this->assertStringContainsString('meaningful text content', strtolower($result['reason']));
        foreach ($result['pageAnalysis'] as $page) {
            $this->assertFalse($page['hasMeaningfulContent']);
        }
    }

public function testMixedMeaningfulAndInsignificantText(): void
    {
        $pages = [
            ['page' => 1, 'text' => "School Header\nPage 1\n\nQuestion 1 (5 marks)\nAnswer: The mitochondria is the powerhouse of the cell.\n\nQuestion 2 (3 marks)\nAnswer: Photosynthesis converts CO2 and water into glucose.\n\nFooter text"],
            ['page' => 2, 'text' => "Page 2\n\nQuestion 3 (2 marks)\nAnswer: \n\nQuestion 4 (5 marks)\nAnswer: The capital of Japan is Tokyo.\n\n(2 marks)\nEnd of paper"],
        ];

        $result = $this->detector->analyze($pages);

        $this->assertSame(TextSufficiencyDecision::TextSufficient, $result['decision']);
        $this->assertTrue($result['pageAnalysis'][0]['hasMeaningfulContent']);
        $this->assertTrue($result['pageAnalysis'][1]['hasMeaningfulContent']);
        $this->assertGreaterThanOrEqual(50, $result['pageAnalysis'][0]['meaningfulTextLength']);
        $this->assertGreaterThanOrEqual(50, $result['pageAnalysis'][1]['meaningfulTextLength']);
    }
}