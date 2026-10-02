<?php
declare(strict_types=1);

namespace App\Service\Analysis\Processing;

use App\DTO\Analysis\DocumentClassificationResult;
use App\Enum\Analysis\DocumentType;
use Psr\Log\LoggerInterface;

readonly class AnswerSheetDocumentClassifier
{
    // Semantic patterns that indicate an answer sheet
    private const ANSWER_SHEET_PATTERNS = [
        // Question numbering patterns
        'question_numbering' => [
            '/\b(?:Question|Q\.?|Qn\.?)\s*\d+[a-z]?\b/i',
            '/^\s*\d+[a-z]?\s*[:\.\)]/m',
            '/\b(?:प्रश्न|Q)\s*\d+\b/iu',
        ],
        // Answer patterns
        'answer_content' => [
            '/(?:^|[\n\r])\s*(?:Answer|Ans\.?|Working|Solution)\b[:\-]?\s*.{5,}/im',
            '/(?:^|[\n\r])\s*(?:उत्तर|कार्य)\b[:\-]?\s*.{5,}/iu',
        ],
        // Multiple choice patterns
        'mcq_response' => [
            '/\b(?:Answer|Ans\.?)\s*:\s*[A-E]\b/i',
            '/\bQ\s*\d+\s*[:\-]?\s*[A-E]\b/i',
            '/\b(?:Option|Option)\s*[A-E]\b/i',
            '/\[?\s*[A-E]\s*\]?(?:\.|\)|\])/m',
        ],
        // Mathematical working
        'math_working' => [
            '#\b(?:Therefore|Hence|So|Thus)\b\s*[A-Za-z0-9+\-*/=]#i',
            '#\b\d+\s*[+\*/=]\s*\d+#',
            '#=\s*\d+(\.\d+)?#',
            '#\b(?:x|y|z)\s*=\s*\d+#i',
        ],
        // Marks/points indicators
        'marks_indicator' => [
            '/\(\s*\d+\s*marks?\s*\)/i',
            '/\(\s*\d+\s*अंक\s*\)/iu',
            '/\b\d+\s*marks?\b/i',
            '/\b\d+\s*अंक\b/iu',
        ],
        // Student/exam metadata
        'metadata_fields' => [
            '/\b(?:Name|Roll|Reg|Subject|Class|Section|Date|Exam|Test)\b\s*[:\-]/i',
            '/\b(?:नाम|रोल|विषय|कक्षा|तिथि|परीक्षा)\b\s*[:\-]/iu',
        ],
        // Handwritten indicators
        'handwritten_markers' => [
            '/hand.?writt|hindi|devanagari|हिंदी|देवनागरी/i',
        ],
        // Multiple question/answer sections
        'multi_section' => [
            '/(?:Question|Q\.?|प्रश्न)\s*\d+.*?(?:Question|Q\.?|प्रश्न)\s*\d+/is',
        ],
    ];

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Classify a document based on extracted text content.
     * For typed PDFs with extractable text.
     */
    public function classifyFromText(string $text, int $pageCount): DocumentClassificationResult
    {
        $signals = $this->analyzeTextSignals($text);
        $scores = $this->calculateScores($signals, $pageCount);
        
        $result = $this->determineClassification($scores, $signals, $pageCount);
        
        $this->logger->info('Document classification from text completed', [
            'document_type' => $result->documentType->value,
            'status' => $result->status,
            'confidence' => $result->confidence,
            'reason' => $result->reason,
            'signals' => $result->signals,
        ]);
        
        return $result;
    }

    /**
     * Classify a document using OpenAI Vision for visual/scanned documents.
     * Returns classification + extraction in one call where practical.
     */
    public function classifyFromVision(array $visionResult): DocumentClassificationResult
    {
        $signals = $this->analyzeVisionSignals($visionResult);
        $scores = $this->calculateVisionScores($signals, count($visionResult['pages'] ?? []));
        
        $result = $this->determineClassification($scores, $signals, count($visionResult['pages'] ?? []));
        
        $this->logger->info('Document classification from Vision completed', [
            'document_type' => $result->documentType->value,
            'status' => $result->status,
            'confidence' => $result->confidence,
            'reason' => $result->reason,
        ]);
        
        return $result;
    }

    private function analyzeTextSignals(string $text): array
    {
        $signals = [];
        
        foreach (self::ANSWER_SHEET_PATTERNS as $category => $patterns) {
            $matches = 0;
            $details = [];
            
            foreach ($patterns as $pattern) {
                $count = preg_match_all($pattern, $text, $matchesArr);
                $matches += $count;
                if ($count > 0) {
                    $details[] = ['pattern' => $pattern, 'matches' => $count];
                }
            }
            
            $signals[$category] = [
                'total_matches' => $matches,
                'patterns' => $details,
                'has_signal' => $matches > 0,
            ];
        }
        
        // Additional: check for question paper indicators
        $signals['question_paper_indicators'] = $this->detectQuestionPaperIndicators($text);
        
        return $signals;
    }

    private function analyzeVisionSignals(array $visionResult): array
    {
        $signals = [];
        $totalQuestions = 0;
        $totalAnswers = 0;
        $questionsWithAnswers = 0;
        $hasMetadata = false;
        
        foreach ($visionResult['pages'] ?? [] as $page) {
            $questions = $page['questions'] ?? [];
            $totalQuestions += count($questions);
            
            foreach ($questions as $q) {
                if (!empty($q['answerText'] ?? '')) {
                    $totalAnswers++;
                    if ($this->hasSubstantialAnswer($q['answerText'])) {
                        $questionsWithAnswers++;
                    }
                }
            }
            
            $metadata = $page['metadata'] ?? [];
            if (!empty(array_filter($metadata))) {
                $hasMetadata = true;
            }
        }
        
        $signals['total_questions'] = $totalQuestions;
        $signals['total_answers'] = $totalAnswers;
        $signals['questions_with_answers'] = $questionsWithAnswers;
        $signals['has_metadata'] = $hasMetadata;
        $signals['answer_rate'] = $totalQuestions > 0 ? $totalAnswers / $totalQuestions : 0;
        $signals['substantial_answer_rate'] = $totalQuestions > 0 ? $questionsWithAnswers / $totalQuestions : 0;
        
        // Check for question paper patterns in vision text
        $allText = $visionResult['text'] ?? '';
        $signals['question_paper_indicators'] = $this->detectQuestionPaperIndicators($allText);
        
        return $signals;
    }

    private function hasSubstantialAnswer(string $answer): bool
    {
        $trimmed = trim($answer);
        if (strlen($trimmed) < 3) return false;
        
        // Check if it's just a placeholder
        $placeholders = [
            '/^\(\d+\s*marks?\)$/i',
            '/^question\s*\d+$/i',
            '/^q\d+$/i',
            '/^answer\s*:?$/i',
            '/^working\s*:?$/i',
            '/^\d+\s*marks?$/i',
            '/^\(\d+\s*अंक\)$/iu',
            '/^उत्तर\s*:?$/iu',
        ];
        
        foreach ($placeholders as $pattern) {
            if (preg_match($pattern, $trimmed)) return false;
        }
        
        return true;
    }

    private function detectQuestionPaperIndicators(string $text): array
    {
        $indicators = [
            'has_questions_without_answers' => false,
            'has_instructional_text' => false,
            'has_time_limits' => false,
            'has_max_marks' => false,
        ];
        
        // Questions without answers (instructional format)
        $questionOnlyCount = preg_match_all('/\b(?:Question|Q\.?|Qn\.?)\s*\d+[a-z]?\b/i', $text);
        $answerCount = preg_match_all('/\b(?:Answer|Ans\.?|Working|Solution)\b[:\-]/i', $text);
        
        if ($questionOnlyCount > $answerCount * 2) {
            $indicators['has_questions_without_answers'] = true;
        }
        
        // Instructional text
        if (preg_match('/\b(?:Instructions?|Directions?|Read carefully|Attempt all|Time allowed|Maximum marks)\b/i', $text)) {
            $indicators['has_instructional_text'] = true;
        }
        
        // Time limits
        if (preg_match('/\b\d+\s*(?:minutes?|hours?|mins?)\b/i', $text)) {
            $indicators['has_time_limits'] = true;
        }
        
        // Max marks
        if (preg_match('/\b(?:Maximum|Max|Total)\s*marks?\s*[:\-]?\s*\d+/i', $text)) {
            $indicators['has_max_marks'] = true;
        }
        
        return $indicators;
    }

    private function calculateScores(array $signals, int $pageCount): array
    {
        $weights = [
            'question_numbering' => 1.0,
            'answer_content' => 3.0,
            'mcq_response' => 2.0,
            'math_working' => 2.0,
            'marks_indicator' => 0.3,
            'metadata_fields' => 0.2,
            'handwritten_markers' => 1.0,
            'multi_section' => 1.5,
        ];
        
        $scores = ['answer_sheet' => 0, 'question_paper' => 0, 'unknown' => 0];
        
        foreach ($weights as $signal => $weight) {
            $hasSignal = $signals[$signal]['has_signal'] ?? false;
            $matchCount = $signals[$signal]['total_matches'] ?? 0;
            
            if ($hasSignal) {
                // Base score + bonus for multiple matches
                $score = $weight * (1 + min($matchCount / 5, 0.5));
                $scores['answer_sheet'] += $score;
            }
        }
        
        // Question paper signals
        $qpSignals = $signals['question_paper_indicators'] ?? [];
        if ($qpSignals['has_questions_without_answers'] ?? false) $scores['question_paper'] += 3.0;
        if ($qpSignals['has_instructional_text'] ?? false) $scores['question_paper'] += 2.0;
        if ($qpSignals['has_time_limits'] ?? false) $scores['question_paper'] += 1.5;
        if ($qpSignals['has_max_marks'] ?? false) $scores['question_paper'] += 1.5;
        
        // Penalty for answer sheets without actual answer content
        $hasAnswerSignal = $signals['answer_content']['has_signal'] ?? false;
        $hasMcqSignal = $signals['mcq_response']['has_signal'] ?? false;
        $hasMathSignal = $signals['math_working']['has_signal'] ?? false;
        
        if (!$hasAnswerSignal && !$hasMcqSignal && !$hasMathSignal) {
            // No actual answers - heavily penalize answer_sheet score
            $scores['answer_sheet'] *= 0.1;
        }
        
        // Page count adjustment
        if ($pageCount > 1) {
            $scores['answer_sheet'] *= 1.2; // Multi-page more likely answer sheet
        }
        
        // No signals = unknown
        if ($scores['answer_sheet'] === 0 && $scores['question_paper'] === 0) {
            $scores['unknown'] = 1.0;
        }
        
        return $scores;
    }

    private function calculateVisionScores(array $signals, int $pageCount): array
    {
        $scores = ['answer_sheet' => 0, 'question_paper' => 0, 'unknown' => 0];
        
        // Strong signals from Vision
        $answerRate = $signals['answer_rate'] ?? 0;
        $substantialRate = $signals['substantial_answer_rate'] ?? 0;
        $totalQuestions = $signals['total_questions'] ?? 0;
        $hasMetadata = $signals['has_metadata'] ?? false;
        
        if ($totalQuestions > 0) {
            // Answer rate is the strongest signal
            $scores['answer_sheet'] += $answerRate * 3.0;
            $scores['answer_sheet'] += $substantialRate * 2.0;
            
            // Question paper indicators
            $qpSignals = $signals['question_paper_indicators'] ?? [];
            if ($qpSignals['has_questions_without_answers'] ?? false) {
                $scores['question_paper'] += 2.0;
                $scores['answer_sheet'] *= 0.3; // Strong penalty
            }
            if ($qpSignals['has_instructional_text'] ?? false) {
                $scores['question_paper'] += 1.0;
            }
        } else {
            // No questions detected
            $scores['unknown'] = 1.0;
        }
        
        // Page count
        if ($pageCount > 1) {
            $scores['answer_sheet'] *= 1.1;
        }
        
        if ($scores['answer_sheet'] === 0 && $scores['question_paper'] === 0) {
            $scores['unknown'] = 1.0;
        }
        
        return $scores;
    }

    private function determineClassification(array $scores, array $signals, int $pageCount): DocumentClassificationResult
    {
        $maxScore = max($scores);
        $totalScore = array_sum($scores);
        
        if ($totalScore === 0) {
            return DocumentClassificationResult::unknown(0.1, ['no_signals_detected' => true]);
        }
        
        // Determine document type
        $documentType = match (array_search($maxScore, $scores, true)) {
            'answer_sheet' => DocumentType::AnswerSheet,
            'question_paper' => DocumentType::QuestionPaper,
            default => DocumentType::Unknown,
        };
        
        // For answer sheet, require answer_content signal (or mcq_response/math_working) for VALID
        // Header-only, marks-only, question paper should not be VALID
        if ($documentType === DocumentType::AnswerSheet) {
            $hasAnswerSignal = $signals['answer_content']['has_signal'] ?? false;
            $hasMcqSignal = $signals['mcq_response']['has_signal'] ?? false;
            $hasMathSignal = $signals['math_working']['has_signal'] ?? false;
            
            if (!$hasAnswerSignal && !$hasMcqSignal && !$hasMathSignal) {
                // Has question numbering/marks but no actual answers - likely question paper or template
                if ($scores['question_paper'] > 0) {
                    return DocumentClassificationResult::questionPaper(
                        $scores['question_paper'] / max(array_sum($scores), 1),
                        ['question_paper_indicators' => true]
                    );
                }
                return DocumentClassificationResult::unknown(0.3, ['no_answer_content' => true]);
            }
            
            // Require minimum score for VALID
            if ($maxScore < 2.0) {
                return DocumentClassificationResult::unknown(0.3, ['insufficient_answer_signals' => true]);
            }
        }
        
        // Calculate confidence (0-1)
        $confidence = $maxScore / max($totalScore, 1);
        $confidence = min(max($confidence, 0.1), 0.99);
        
        // Build signals summary for output
        $outputSignals = [];
        foreach ($signals as $key => $value) {
            if (is_array($value) && isset($value['has_signal'])) {
                if ($value['has_signal']) {
                    $outputSignals[] = $key;
                }
            } elseif (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    if ($subValue === true) {
                        $outputSignals[] = "{$key}:{$subKey}";
                    }
                }
            }
        }
        
        // Determine result
        if ($documentType === DocumentType::AnswerSheet) {
            return DocumentClassificationResult::validAnswerSheet($confidence, $outputSignals);
        } elseif ($documentType === DocumentType::QuestionPaper) {
            return DocumentClassificationResult::questionPaper($confidence, $outputSignals);
        } else {
            return DocumentClassificationResult::unknown($confidence, $outputSignals);
        }
    }
}