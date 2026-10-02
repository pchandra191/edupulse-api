<?php

declare(strict_types=1);

namespace App\Service\Analysis\Processing;

use App\DTO\Analysis\ExtractedAnswer;
use App\DTO\Analysis\ExtractedAnswerSheetMetadata;
use Psr\Log\LoggerInterface;

/**
 * Validates whether an extraction result contains meaningful content.
 * 
 * This service determines if SetaPDF extraction produced usable results
 * or if OCR fallback is needed.
 */
readonly class ExtractionQualityValidator
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *     usable: bool,
     *     reason: string,
     *     meaningfulAnswerCount: int,
     *     meaningfulQuestionCount: int,
     *     metadataCount: int,
     *     pageAnalysis: array<array{
     *         page: int,
     *         hasMeaningfulContent: bool,
     *         textLength: int,
     *         answerCount: int,
     *         placeholderCount: int
     *     }>
     * }
     */
    public function validate(array $extractionResult, array $pages): array
    {
        $answers = $extractionResult['answers'] ?? [];
        $metadata = $extractionResult['metadata'] ?? null;
        
        // Analyze each page for meaningful content
        $pageAnalysis = [];
        $totalMeaningfulAnswers = 0;
        $totalPlaceholderAnswers = 0;
        
        foreach ($pages as $page) {
            $pageNumber = $page['page'] ?? 1;
            $pageText = $page['text'] ?? '';
            $textLength = strlen(trim($pageText));
            
            // Count answers for this page
            $pageAnswers = array_filter($answers, fn($a) => ($a->page ?? $a->sourcePage ?? 1) == $pageNumber);
            $pageAnswerCount = count($pageAnswers);
            
            $meaningfulOnPage = 0;
            $placeholderOnPage = 0;
            
            foreach ($pageAnswers as $answer) {
                $answerText = trim($answer->answerText ?? '');
                if ($answerText === '') {
                    continue;
                }
                if ($this->isPlaceholderText($answerText)) {
                    $placeholderOnPage++;
                    $totalPlaceholderAnswers++;
                } else {
                    $meaningfulOnPage++;
                    $totalMeaningfulAnswers++;
                }
            }
            
            $pageAnalysis[] = [
                'page' => $pageNumber,
                'hasMeaningfulContent' => $meaningfulOnPage > 0,
                'textLength' => $textLength,
                'answerCount' => $pageAnswerCount,
                'meaningfulAnswerCount' => $meaningfulOnPage,
                'placeholderCount' => $placeholderOnPage,
            ];
        }
        
        // Count meaningful answers across all pages
        $meaningfulAnswers = 0;
        foreach ($answers as $answer) {
            $answerText = trim($answer->answerText ?? '');
            if ($answerText !== '' && !$this->isPlaceholderText($answerText)) {
                $meaningfulAnswers++;
            }
        }
        
        // Count meaningful metadata
        $metadataCount = 0;
        if ($metadata !== null) {
            $fields = [
                'studentName', 'rollNumber', 'subject', 'examTitle',
                'academicYear', 'class', 'section', 'totalMarks',
                'scoredMarks', 'examDate', 'examSubType', 'instituteName'
            ];
            
            foreach ($fields as $field) {
                $value = $metadata->$field;
                if ($value !== null && $value->isAvailable()) {
                    $metadataCount++;
                }
            }
        }
        
        // Determine if extraction is usable
        $usable = false;
        $reason = '';
        
        if ($meaningfulAnswers >= 1) {
            $usable = true;
            $reason = "Found {$meaningfulAnswers} meaningful answer(s)";
        } elseif ($metadataCount >= 3 && count($answers) > 0) {
            // Has metadata and some answer structure (even if answers are empty/placeholders)
            // This might be a structured form with blank answers
            $usable = true;
            $reason = "Found {$metadataCount} metadata fields with answer structure";
        } elseif ($totalPlaceholderAnswers > 0 && $meaningfulAnswers === 0) {
            $usable = false;
            $reason = "Only placeholder answers found (e.g., '(5 marks)', 'Question 1', 'Answer:') - {$totalPlaceholderAnswers} placeholders detected";
        } elseif (count($answers) === 0 && $metadataCount === 0) {
            $usable = false;
            $reason = "No answers and no metadata extracted";
        } else {
            $usable = false;
            $reason = "Insufficient meaningful content (answers: {$meaningfulAnswers}, metadata fields: {$metadataCount})";
        }
        
        $this->logger->debug('Extraction quality validation', [
            'usable' => $usable,
            'reason' => $reason,
            'meaningful_answers' => $meaningfulAnswers,
            'placeholder_answers' => $totalPlaceholderAnswers,
            'metadata_fields' => $metadataCount,
            'total_pages' => count($pages),
        ]);
        
        return [
            'usable' => $usable,
            'reason' => $reason,
            'meaningfulAnswerCount' => $meaningfulAnswers,
            'meaningfulQuestionCount' => count($answers),
            'metadataCount' => $metadataCount,
            'pageAnalysis' => $pageAnalysis,
        ];
    }
    
    private function isPlaceholderText(string $text): bool
    {
        $placeholders = [
            // English placeholders
            '/^\(\d+\s*marks?\)$/i',
            '/^question\s*\d+$/i',
            '/^q\d+$/i',
            '/^answer\s*:?$/i',
            '/^working\s*:?$/i',
            '/^answer\s*\/\s*working$/i',
            '/^\d+\s*marks?$/i',
            // Hindi/Devanagari placeholders
            '/^प्रश्न\s*\d+$/iu',
            '/^उत्तर\s*:?$/iu',
            '/^कार्य\s*:?$/iu',
            '/^\(\d+\s*अंक\)$/iu',
            '/^\d+\s*अंक$/iu',
            '/^अंक\s*\(\d+\)$/iu',
        ];
        
        $trimmed = trim($text);
        foreach ($placeholders as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return true;
            }
        }
        return false;
    }
}