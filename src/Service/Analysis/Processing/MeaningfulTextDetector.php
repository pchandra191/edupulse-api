<?php
declare(strict_types=1);

namespace App\Service\Analysis\Processing;

use App\Enum\Analysis\TextSufficiencyDecision;
use Psr\Log\LoggerInterface;

readonly class MeaningfulTextDetector
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Analyze raw extracted text from SetaPDF to determine if it contains
     * meaningful student-answer content or if visual extraction (OCR) is required.
     *
     * @param array<array{page: int, text: string}> $pages
     * @return array{
     *     decision: TextSufficiencyDecision,
     *     reason: string,
     *     pageAnalysis: array<array{
     *         page: int,
     *         textLength: int,
     *         meaningfulTextLength: int,
     *         hasMeaningfulContent: bool,
     *         rejectionReason: string|null
     *     }>
     * }
     */
    public function analyze(array $pages): array
    {
        $pageAnalysis = [];
        $totalMeaningfulLength = 0;
        $pagesWithMeaningfulContent = 0;

        foreach ($pages as $page) {
            $pageNumber = $page['page'] ?? 1;
            $rawText = $page['text'] ?? '';
            $textLength = strlen($rawText);

            // Clean the text by removing known insignificant patterns
            $meaningfulText = $this->filterInsignificantText($rawText);
            $meaningfulTextLength = strlen(trim($meaningfulText));

            $hasMeaningfulContent = $meaningfulTextLength >= 50; // Minimum threshold
            $rejectionReason = null;

            if (!$hasMeaningfulContent) {
                $rejectionReason = $this->determineRejectionReason($rawText, $meaningfulTextLength);
            }

            if ($hasMeaningfulContent) {
                $pagesWithMeaningfulContent++;
                $totalMeaningfulLength += $meaningfulTextLength;
            }

            $pageAnalysis[] = [
                'page' => $pageNumber,
                'textLength' => $textLength,
                'meaningfulTextLength' => $meaningfulTextLength,
                'hasMeaningfulContent' => $hasMeaningfulContent,
                'rejectionReason' => $rejectionReason,
            ];

            $this->logger->debug('Page text analysis', [
                'page' => $pageNumber,
                'text_length' => $textLength,
                'meaningful_length' => $meaningfulTextLength,
                'has_meaningful' => $hasMeaningfulContent,
                'rejection_reason' => $rejectionReason,
            ]);
        }

        // Decision logic
        $decision = TextSufficiencyDecision::VisualExtractionRequired;
        $reason = '';

        if ($pagesWithMeaningfulContent === 0) {
            $reason = 'No pages contain meaningful text content';
        } elseif ($pagesWithMeaningfulContent < count($pages) * 0.5) {
            // Less than 50% of pages have meaningful content - likely scanned document
            $decision = TextSufficiencyDecision::VisualExtractionRequired;
            $reason = "Only {$pagesWithMeaningfulContent} of " . count($pages) . " pages have meaningful text";
        } elseif ($totalMeaningfulLength < 100) {
            // Total meaningful content across all pages is too little
            $decision = TextSufficiencyDecision::VisualExtractionRequired;
            $reason = "Total meaningful text length ({$totalMeaningfulLength}) below threshold";
        } else {
            $decision = TextSufficiencyDecision::TextSufficient;
            $reason = "Found meaningful text on {$pagesWithMeaningfulContent} pages (total {$totalMeaningfulLength} chars)";
        }

        $this->logger->info('Meaningful text detection completed', [
            'decision' => $decision->value,
            'reason' => $reason,
            'pages_analyzed' => count($pages),
            'pages_with_meaningful' => $pagesWithMeaningfulContent,
            'total_meaningful_length' => $totalMeaningfulLength,
        ]);

        return [
            'decision' => $decision,
            'reason' => $reason,
            'pageAnalysis' => $pageAnalysis,
        ];
    }

    /**
     * Filter out known insignificant text patterns from raw PDF text.
     */
    private function filterInsignificantText(string $text): string
    {
        $patternsToRemove = [
            // Page numbers (standalone numbers on their own line)
            '/^\s*\d+\s*$/m',
            // Headers/footers with common patterns
            '/^\s*(?:Page|Pg\.?)\s*\d+\s*$/im',
            // "(X marks)" patterns
            '/\(\s*\d+\s*marks?\s*\)/i',
            // "Question X (X marks)" - entire line when it's just a question header with marks
            '/^\s*(?:Question|Q)\s*\d+[a-z]?\s*(?:\(\s*\d+\s*marks?\s*\))?\s*$/im',
            // Empty answer placeholders
            '/^\s*(?:Answer|Working|Solution)\s*[:\-\/]?\s*$/im',
            // Common header/footer lines - match even with values (field: value format)
            '/^\s*(?:Name|Roll|Reg|Subject|Class|Section|Date|Exam|Test)\s*[:\-]\s*\S.*$/im',
            // Standalone marks indicators
            '/^\s*\d+\s*marks?\s*$/i',
            // "Total: X marks" or "Total X marks"
            '/^\s*Total\s*[:\-]?\s*\d+\s*marks?\s*$/i',
            // Hindi/Devanagari equivalents
            '/^\s*प्रश्न\s*\d+\s*$/iu',
            '/^\s*उत्तर\s*[:\-]?\s*$/iu',
            '/^\s*कार्य\s*[:\-]?\s*$/iu',
            '/^\s*\(\s*\d+\s*अंक\s*\)\s*$/iu',
            '/^\s*\d+\s*अंक\s*$/iu',
            // Multiple whitespace lines
            '/\n\s*\n\s*\n+/', // 3+ consecutive empty lines
        ];

        $filtered = $text;
        foreach ($patternsToRemove as $pattern) {
            $filtered = preg_replace($pattern, '', $filtered);
        }

        // Clean up excessive whitespace
        $filtered = preg_replace('/[ \t]+/', ' ', $filtered); // Multiple spaces/tabs to single space
        $filtered = preg_replace('/\n{3,}/', "\n\n", $filtered); // Max 2 consecutive newlines
        $filtered = trim($filtered);

        return $filtered;
    }

    /**
     * Determine why text was rejected as insignificant.
     */
    private function determineRejectionReason(string $rawText, int $meaningfulLength): string
    {
        $trimmed = trim($rawText);

        if ($trimmed === '') {
            return 'Empty text';
        }

        if ($meaningfulLength === 0) {
            if (preg_match('/^\s*\d+\s*$/m', $trimmed)) {
                return 'Only page numbers';
            }
            if (preg_match('/\(\s*\d+\s*marks?\s*\)/i', $trimmed)) {
                return 'Only marks indicators';
            }
            if (preg_match('/^\s*(?:Question|Q)\s*\d+[a-z]?\s*[:\-\.]?\s*$/im', $trimmed)) {
                return 'Only question headers';
            }
            if (preg_match('/^\s*(?:Answer|Working|Solution)\s*[:\-\/]?\s*$/im', $trimmed)) {
                return 'Only answer placeholders';
            }
            if (strlen($trimmed) < 50) {
                return 'Text too short (likely headers/footers only)';
            }
            return 'All text filtered as insignificant';
        }

        if ($meaningfulLength < 50) {
            return 'Meaningful text too short after filtering';
        }

        return 'Insufficient meaningful content';
    }
}