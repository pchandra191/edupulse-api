<?php
declare(strict_types=1);

namespace App\Service\Analysis\Processing;

use Psr\Log\LoggerInterface;

readonly class QuestionExtractor
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<ExtractedQuestion>
     */
    public function extractQuestions(array $questionPaperPages, array $answerSheetPages = []): array
    {
        $questions = [];
        
        // Combine all question paper text
        $fullText = '';
        foreach ($questionPaperPages as $page) {
            $fullText .= "\n--- PAGE {$page['page']} ---\n" . $page['text'];
        }
        
        // Simple regex-based question extraction
        // This is a basic implementation - can be enhanced with more sophisticated parsing
        $questionPatterns = [
            // Pattern 1: "Question X" or "Q.X" or "Q X"
            '/(?:Question|Q)\.?\s*(\d+[a-z]?)\s*[:\-\.]?\s*(.+?)(?=(?:Question|Q)\.?\s*\d+|$)/is',
            // Pattern 2: Numbered list "1." "2." etc.
            '/(?:^|\n)\s*(\d+)\.\s*(.+?)(?=\n\s*\d+\.|$)/m',
            // Pattern 3: "Q14" style
            '/Q(\d+[a-z]?)\s*[:\-\.]?\s*(.+?)(?=Q\d+|$)/is',
        ];
        
        $matches = [];
        
        foreach ($questionPatterns as $pattern) {
            if (preg_match_all($pattern, $fullText, $patternMatches, PREG_SET_ORDER)) {
                foreach ($patternMatches as $match) {
                    if (isset($match[1], $match[2])) {
                        $number = trim($match[1]);
                        $text = trim($match[2]);
                        
                        // Skip if too short or looks like noise
                        if (strlen($text) < 10) {
                            continue;
                        }
                        
                        $key = 'q_' . $number;
                        if (!isset($matches[$key])) {
                            $matches[$key] = [
                                'number' => $number,
                                'text' => $text,
                            ];
                        }
                    }
                }
            }
        }
        
        // Also try to extract marks
        $marksPattern = '/\((\d+)\s*marks?\)/i';
        $marksMatches = [];
        if (preg_match_all($marksPattern, $fullText, $marksResult, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($marksResult as $match) {
                $marksMatches[$match[0][1]] = (int) $match[1][0];
            }
        }
        
        // Build extracted questions
        foreach ($matches as $key => $match) {
            $number = $match['number'];
            $text = $this->cleanQuestionText($match['text']);
            
            // Find nearest marks
            $marks = $this->findNearestMarks($fullText, $number, $marksMatches);
            
            // Determine source page
            $sourcePage = $this->findSourcePage($questionPaperPages, $text);
            
            // Determine question type
            $type = $this->inferQuestionType($text);
            
            $questions[] = new ExtractedQuestion(
                questionNumber: ProvenanceValue::extracted($number, 0.95),
                questionText: ProvenanceValue::extracted($text, 0.9),
                marks: $marks !== null 
                    ? ProvenanceValue::extracted($marks, 0.85)
                    : ProvenanceValue::missing(),
                type: ProvenanceValue::inferred($type, 0.7),
                source: [
                    'page' => $sourcePage,
                    'bbox' => null,
                ],
            );
        }
        
        // Sort by question number
        usort($questions, function (ExtractedQuestion $a, ExtractedQuestion $b) {
            return $this->compareQuestionNumbers(
                $a->questionNumber->value ?? '',
                $b->questionNumber->value ?? ''
            );
        });
        
        $this->logger->info('Extracted questions from question paper', [
            'count' => count($questions),
        ]);
        
        return $questions;
    }
    
    /**
     * @return array<ExtractedQuestion>
     */
    public function mapAnswersToQuestions(array $questions, array $answerSheetPages): array
    {
        // Combine all answer sheet text
        $answerText = '';
        foreach ($answerSheetPages as $page) {
            $answerText .= "\n--- PAGE {$page['page']} ---\n" . $page['text'];
        }
        
        // Try to map answers to questions
        // This is a simplified implementation
        $answerPatterns = [
            // Pattern: "Q1: answer" (short format) - more specific, try first
            '/(?:^|\n)\s*Q(\d+[a-z]?)\s*[:\-]\s*([^\n]+)/im',
            // Pattern: "Q14" or "Question 14" followed by answer
            '/(?:Q|Question)\.?\s*(\d+[a-z]?)\s*[:\-\.]?\s*(.+?)(?=(?:Q|Question)\.?\s*\d+|$)/im',
            // Pattern: Numbered answers
            '/(?:^|\n)\s*(\d+)\.\s*(.+?)(?=\n\s*\d+\.|$)/m',
        ];
        
        $answerMatches = [];
        foreach ($answerPatterns as $pattern) {
            if (preg_match_all($pattern, $answerText, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (isset($match[1], $match[2])) {
                        $number = trim($match[1]);
                        $text = trim($match[2]);
                        $text = preg_replace('/\s+$/', '', $text); // Remove trailing whitespace including newlines
                        if (strlen($text) >= 1) {
                            $answerMatches['q_' . $number] = $text;
                        }
                    }
                }
            }
        }
        
        // Map answers to questions
        $questionsWithAnswers = [];
        foreach ($questions as $question) {
            $qNum = $question->questionNumber->value ?? '';
            $key = 'q_' . $qNum;
            
            if (isset($answerMatches[$key])) {
                $answerPage = $this->findSourcePage($answerSheetPages, $answerMatches[$key]);
                
                $questionsWithAnswers[] = new ExtractedQuestion(
                    questionNumber: $question->questionNumber,
                    questionText: $question->questionText,
                    marks: $question->marks,
                    type: $question->type,
                    source: $question->source,
                    answerText: ProvenanceValue::extracted($answerMatches[$key], 0.8),
                    answerSource: [
                        'page' => $answerPage,
                        'bbox' => null,
                    ],
                );
            } else {
                $questionsWithAnswers[] = $question;
            }
        }
        
        return $questionsWithAnswers;
    }
    
    private function cleanQuestionText(string $text): string
    {
        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        // Remove common noise
        $text = preg_replace('/^\s*[\-\*]\s*/', '', $text);
        return trim($text);
    }
    
    private function findNearestMarks(string $fullText, string $questionNumber, array $marksMatches): ?int
    {
        // Find position of question in text
        $questionPos = stripos($fullText, $questionNumber);
        if ($questionPos === false) {
            return null;
        }
        
        $nearestMarks = null;
        $nearestDistance = PHP_INT_MAX;
        
        foreach ($marksMatches as $pos => $marks) {
            $distance = abs($pos - $questionPos);
            if ($distance < $nearestDistance && $distance < 500) { // Within 500 chars
                $nearestDistance = $distance;
                $nearestMarks = $marks;
            }
        }
        
        return $nearestMarks;
    }
    
    private function findSourcePage(array $pages, string $searchText): ?int
    {
        $searchSnippet = substr($searchText, 0, 100);
        foreach ($pages as $page) {
            if (stripos($page['text'], $searchSnippet) !== false) {
                return $page['page'];
            }
        }
        return $pages[0]['page'] ?? 1;
    }
    
    private function inferQuestionType(string $text): string
    {
        $textLower = strtolower($text);
        
        if (preg_match('/\b(calculate|compute|solve|find|determine)\b/i', $textLower)) {
            if (preg_match('/\b\d+\.?\d*\b/', $textLower)) {
                return 'numerical';
            }
            return 'short_answer';
        }
        
        if (preg_match('/\b(choose|select|which|multiple choice|mcq)\b/i', $textLower)) {
            return 'multiple_choice';
        }
        
        if (preg_match('/\b(true|false|t\/f)\b/i', $textLower)) {
            return 'true_false';
        }
        
        if (preg_match('/\b(explain|describe|discuss|analyze|evaluate)\b/i', $textLower)) {
            return 'essay';
        }
        
        if (preg_match('/\b(fill|blank|complete)\b/i', $textLower)) {
            return 'fill_blank';
        }
        
        return 'short_answer';
    }
    
    private function compareQuestionNumbers(string $a, string $b): int
    {
        // Extract numeric parts for comparison
        preg_match('/(\d+)/', $a, $aMatch);
        preg_match('/(\d+)/', $b, $bMatch);
        
        $aNum = $aMatch[1] ?? 0;
        $bNum = $bMatch[1] ?? 0;
        
        if ($aNum !== $bNum) {
            return $aNum - $bNum;
        }
        
        // If numeric parts equal, compare full strings
        return strcmp($a, $b);
    }
}