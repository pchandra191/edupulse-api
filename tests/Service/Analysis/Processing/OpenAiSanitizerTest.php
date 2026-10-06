<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis\Processing;

use App\Service\Analysis\Processing\OpenAiSanitizer;
use PHPUnit\Framework\TestCase;

class OpenAiSanitizerTest extends TestCase
{
    private OpenAiSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new OpenAiSanitizer();
    }

    public function testSanitizePreservesNormalText(): void
    {
        $input = 'Hello, World!';
        $output = $this->sanitizer->sanitize($input);
        $this->assertSame('Hello, World!', $output);
    }

    public function testSanitizePreservesNewlines(): void
    {
        $input = "Line 1\nLine 2\nLine 3";
        $output = $this->sanitizer->sanitize($input);
        $this->assertSame("Line 1\nLine 2\nLine 3", $output);
    }

    public function testSanitizePreservesTabs(): void
    {
        $input = "Col1\tCol2\tCol3";
        $output = $this->sanitizer->sanitize($input);
        $this->assertSame("Col1\tCol2\tCol3", $output);
    }

    public function testSanitizePreservesCarriageReturns(): void
    {
        $input = "Line 1\r\nLine 2";
        $output = $this->sanitizer->sanitize($input);
        $this->assertSame("Line 1\r\nLine 2", $output);
    }

    public function testSanitizeRemovesNullByte(): void
    {
        $input = "Hello\x00World";
        $output = $this->sanitizer->sanitize($input);
        $this->assertSame('HelloWorld', $output);
    }

    public function testSanitizeRemovesControlChars(): void
    {
        $input = "Start\x01\x02\x03End";
        $output = $this->sanitizer->sanitize($input);
        $this->assertSame('StartEnd', $output);
    }

    public function testSanitizeRemovesVerticalTabAndFormFeed(): void
    {
        $input = "Start\x0B\x0CEnd";
        $output = $this->sanitizer->sanitize($input);
        $this->assertSame('StartEnd', $output);
    }

    public function testSanitizeRemovesControlChars0ETo1F(): void
    {
        $input = "Start\x0E\x0F\x10\x1FEnd";
        $output = $this->sanitizer->sanitize($input);
        $this->assertSame('StartEnd', $output);
    }

    public function testSanitizeRemovesDel(): void
    {
        $input = "Start\x7FEnd";
        $output = $this->sanitizer->sanitize($input);
        $this->assertSame('StartEnd', $output);
    }

    public function testSanitizePreservesHindiText(): void
    {
        $input = 'भारत का राष्ट्रीय पशु बाघ है।';
        $output = $this->sanitizer->sanitize($input);
        $this->assertSame('भारत का राष्ट्रीय पशु बाघ है।', $output);
    }

    public function testSanitizePreservesMixedHindiEnglish(): void
    {
        $input = 'प्रकाश संश्लेषण में plants सूर्य के प्रकाश का उपयोग करते हैं।';
        $output = $this->sanitizer->sanitize($input);
        $this->assertSame('प्रकाश संश्लेषण में plants सूर्य के प्रकाश का उपयोग करते हैं।', $output);
    }

    public function testSanitizePreservesMathematicalSymbols(): void
    {
        $input = 'x² + y² = z² ∑ ∫ ∂ √ ∞ ± ≠ ≤ ≥';
        $output = $this->sanitizer->sanitize($input);
        $this->assertSame('x² + y² = z² ∑ ∫ ∂ √ ∞ ± ≠ ≤ ≥', $output);
    }

    public function testSanitizePreservesEmoji(): void
    {
        $input = 'Hello 😀 World 🎉';
        $output = $this->sanitizer->sanitize($input);
        $this->assertSame('Hello 😀 World 🎉', $output);
    }

    public function testSanitizeRemovesNullByteInMiddle(): void
    {
        $input = "Test\x00String";
        $output = $this->sanitizer->sanitize($input);
        $this->assertSame('TestString', $output);
    }

    public function testSanitizeEmptyString(): void
    {
        $input = '';
        $output = $this->sanitizer->sanitize($input);
        $this->assertSame('', $output);
    }

    public function testSanitizeMultilineTextWithControlChars(): void
    {
        $input = "Line 1\x00\nLine 2\x0B\nLine 3";
        $output = $this->sanitizer->sanitize($input);
        $this->assertSame("Line 1\nLine 2\nLine 3", $output);
    }

    public function testSanitizePreservesNumbersAndPunctuation(): void
    {
        $input = 'Question 1: What is 2+2? Answer: 4 (four).';
        $output = $this->sanitizer->sanitize($input);
        $this->assertSame('Question 1: What is 2+2? Answer: 4 (four).', $output);
    }

    public function testSanitizeArrayPreservesKeys(): void
    {
        $input = [
            'field1' => "Value\x001",
            'field2' => "Value\x0B2",
            'nested' => [
                'deep' => "Deep\x00Value",
            ],
            'number' => 42,
            'bool' => true,
        ];
        $output = $this->sanitizer->sanitizeArray($input);
        $this->assertSame('Value1', $output['field1']);
        $this->assertSame('Value2', $output['field2']);
        $this->assertSame('DeepValue', $output['nested']['deep']);
        $this->assertSame(42, $output['number']);
        $this->assertTrue($output['bool']);
    }

    public function testGetSanitizationStatsValidUtf8(): void
    {
        $original = 'Hello World';
        $cleaned = $this->sanitizer->sanitize($original);
        $stats = $this->sanitizer->getSanitizationStats($original, $cleaned);
        $this->assertTrue($stats['utf8Valid']);
        $this->assertSame(0, $stats['controlCharsRemoved']);
    }

    public function testGetSanitizationStatsInvalidUtf8(): void
    {
        // Create a string with invalid UTF-8 (truncated multi-byte sequence)
        $original = "Hello \xE2\x82"; // Truncated Euro sign
        $cleaned = $this->sanitizer->sanitize($original);
        $stats = $this->sanitizer->getSanitizationStats($original, $cleaned);
        $this->assertFalse($stats['utf8Valid']);
    }

    public function testGetSanitizationStatsControlCharsRemoved(): void
    {
        $original = "Hello\x00\x01\x02World";
        $cleaned = $this->sanitizer->sanitize($original);
        $stats = $this->sanitizer->getSanitizationStats($original, $cleaned);
        $this->assertSame(3, $stats['controlCharsRemoved']);
    }

    public function testSanitizePreservesMixedContent(): void
    {
        $input = "Question 1:\nप्रकाश संश्लेषण में plants सूर्य के प्रकाश का उपयोग करते हैं।\nAnswer: 4";
        $output = $this->sanitizer->sanitize($input);
        $this->assertSame("Question 1:\nप्रकाश संश्लेषण में plants सूर्य के प्रकाश का उपयोग करते हैं।\nAnswer: 4", $output);
    }
}