<?php

declare(strict_types=1);

namespace App\Service\Analysis\Processing;

use App\DTO\Analysis\ExtractedAnswer;
use App\DTO\Analysis\ExtractedAnswerSheetMetadata;
use App\Service\Analysis\Processing\ProvenanceValue;
use Psr\Log\LoggerInterface;

readonly class AnswerSheetExtractor
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Extract structured metadata from answer sheet text
     * 
     * @return array{
     *     metadata: ExtractedAnswerSheetMetadata,
     *     answers: array<ExtractedAnswer>,
     *     extractionResult: string,
     *     extractionConfidence: float,
     * }
     */
    public function extractFromAnswerSheet(array $answerSheetPages): array
    {
        // Combine all answer sheet text
        $fullText = '';
        foreach ($answerSheetPages as $page) {
            $fullText .= "\n--- PAGE {$page['page']} ---\n" . $page['text'];
        }

        // Check if we have meaningful text
        $textLength = strlen(trim($fullText));
        if ($textLength < 50) {
            $this->logger->warning('Answer sheet text too short or empty', [
                'text_length' => $textLength,
                'pages' => count($answerSheetPages),
            ]);
            
            return [
                'metadata' => new ExtractedAnswerSheetMetadata(),
                'answers' => [],
                'extractionResult' => 'NO_TEXT_DETECTED',
                'extractionConfidence' => 0.0,
            ];
        }

        $this->logger->info('Extracting metadata from answer sheet', [
            'text_length' => $textLength,
            'pages' => count($answerSheetPages),
        ]);

        // Extract metadata using regex patterns
        $metadata = $this->extractMetadata($fullText);
        
        // Extract individual answers
        $answers = $this->extractAnswers($answerSheetPages);

        // Calculate overall confidence
        $extractionConfidence = $this->calculateConfidence($metadata, $answers, $textLength);
        
        $extractionResult = $this->determineExtractionResult($metadata, $answers, $textLength);

        $this->logger->info('Answer sheet extraction completed', [
            'extraction_result' => $extractionResult,
            'answers_found' => count($answers),
            'metadata_fields_found' => $this->countMetadataFields($metadata),
            'confidence' => $extractionConfidence,
        ]);

        return [
            'metadata' => $metadata,
            'answers' => $answers,
            'extractionResult' => $extractionResult,
            'extractionConfidence' => $extractionConfidence,
        ];
    }

    private function extractMetadata(string $fullText): ExtractedAnswerSheetMetadata
    {
        $metadata = new ExtractedAnswerSheetMetadata();
        $text = $fullText;

        // Student name patterns - handle tabs and various formats
        $namePatterns = [
            '/(?:Student\s*Name|Name|Student)\s*[:\-\t]\s*([^\n\r\t]+?)(?:\s*(?:Roll|$))/i',
            '/Student\s*Name\s*[\t:]+\s*([^\n\r\t]+)/i',
            '/Student\s*Name\s*[\t:]+\s*([^\n\r\t]+)/i',
        ];

        // Roll number patterns - handle tabs and various formats
        $rollPatterns = [
            '/(?:Roll\s*(?:No|Number|#)?)\s*[:\-]\s*([A-Z0-9\-\/]+)/i',
            '/(?:Reg|Registration)\s*(?:No|Number)?\s*[:\-]\s*([A-Z0-9\-\/]+)/i',
            '/(?:Admission|Enrollment)\s*(?:No|Number)?\s*[:\-]\s*([A-Z0-9\-\/]+)/i',
            '/Roll\s*Number\s*[\t:]+\s*([A-Z0-9\-\/]+)/i',
        ];

// Subject patterns - handle tabs and various formats
        // Stop at "Total Marks", "Total Marks:", "Total Marks:", "Total Marks:", or end of line
        // Also handle case where there's no colon after "Subject"
        $subjectPatterns = [
            '/(?:Subject|Sub)\s*[:\-]?\s*([^\n\r]+?)(?:\s*(?:Total\s*Marks|Total\s*Marks|Exam|Exam:|$))/i',
            '/Subject\s*[\t:]+\s*([^\n\r\t]+)/i',
        ];

// Exam title patterns - prioritize specific exam types
        $examTitlePatterns = [
            // Match "Mid Term Examination" specifically first - use a more robust pattern
            '/\bMid\s*Term\s*Examination\b/iu',
            // Match "Unit Test Examination" specifically - match the full phrase before "·"
            '/\bUnit\s*Test\s*Examination\b/iu',
            // Match other specific exam types - capture only the exam name, not trailing class/section
            '/\b(?:Mid\s*Term|Final|Unit\s*Test|Quarterly|Half\s*Yearly|Annual|Term\s*Exam)\s*(?:Examination)?\b/iu',
            // Generic exam title pattern - must come last
            '/(?:Exam|Examination|Test)\s*(?:Title|Name)?\s*[:\-]\s*([^\n\r]+?)(?:\s*(?:Academic|Year|Class|Section|$))/i',
        ];

        // Academic year patterns
        $academicYearPatterns = [
            '/(?:Academic\s*Year|Year|Session)\s*[:\-]\s*(\d{4}[\-\/]\d{2,4})/i',
            '/(\d{4}[\-\/]\d{2,4})/i',
        ];

        // Class patterns
        $classPatterns = [
            '/(?:Class|Grade|Standard|Std)\s*[:\-]\s*(\d+[A-Z]?)/i',
            '/(?:Grade|Class)\s*(\d+)/i',
        ];

        // Section patterns
        $sectionPatterns = [
            '/(?:Section|Sec)\s*[:\-]\s*([A-Z])/i',
            '/Section\s*([A-Z])/i',
        ];

        // Total marks patterns - handle "Total Marks 30" (space separator) and "Total Marks: 30"
        $totalMarksPatterns = [
            '/(?:Total\s*(?:Marks?|Points?|Score))\s*[:\-]?\s*(\d+)/i',
            '/(?:Max|Maximum)\s*(?:Marks?|Score)\s*[:\-]\s*(\d+)/i',
            '/(?:Out\s*of|Out\s*of\s*Total)\s*(\d+)/i',
            '/Total\s*Marks\s*(\d+)/i',
        ];

        // Scored marks patterns
        $scoredMarksPatterns = [
            '/(?:Scored|Obtained|Gained|Secured)\s*(?:Marks?|Score|Points?)\s*[:\-]\s*(\d+)/i',
            '/(?:Marks?\s*Obtained)\s*[:\-]\s*(\d+)/i',
        ];

        // Exam date patterns
        $examDatePatterns = [
            '/(?:Date|Exam\s*Date)\s*[:\-]\s*(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/i',
            '/(?:Date)\s*[:\-]\s*(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/i',
        ];

        // Exam sub-type patterns
        $examSubTypePatterns = [
            '/(?:Exam\s*Sub\s*Type|Sub\s*Type|Type)\s*[:\-]\s*([^\n\r]+)/i',
            '/(?:Mid\s*Term|Final|Unit\s*Test|Quarterly|Half\s*Yearly|Annual|Term\s*Exam)/i',
        ];

        // Institute name patterns
        $institutePatterns = [
            '/(?:Institute|School|College|University|Academy)\s*[:\-]\s*([^\n\r]+)/i',
        ];

        // Helper function to extract first match
        $extractFirst = function (string $text, array $patterns): ?string {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text, $matches)) {
                    if (isset($matches[1])) {
                        return trim($matches[1]);
                    } elseif (isset($matches[0])) {
                        return trim($matches[0]);
                    }
                }
            }
            return null;
        };

        // Extract each field
        $studentName = $extractFirst($text, $namePatterns);
        $rollNumber = $extractFirst($text, $rollPatterns);
        $subject = $extractFirst($text, $subjectPatterns);
        $examTitle = $extractFirst($text, $examTitlePatterns);
        $academicYear = $extractFirst($text, $academicYearPatterns);
        $class = $extractFirst($text, $classPatterns);
        $section = $extractFirst($text, $sectionPatterns);
        $totalMarksStr = $extractFirst($text, $totalMarksPatterns);
        $scoredMarksStr = $extractFirst($text, $scoredMarksPatterns);
        $examDate = $extractFirst($text, $examDatePatterns);
        $examSubType = $extractFirst($text, $examSubTypePatterns);
        $instituteName = $extractFirst($text, $institutePatterns);

        // Create metadata with confidence values
        $metadata = new ExtractedAnswerSheetMetadata(
            studentName: $studentName ? ProvenanceValue::extracted($studentName, 0.85) : ProvenanceValue::missing(),
            rollNumber: $rollNumber ? ProvenanceValue::extracted($rollNumber, 0.85) : ProvenanceValue::missing(),
            subject: $subject ? ProvenanceValue::extracted($subject, 0.85) : ProvenanceValue::missing(),
            examTitle: $examTitle ? ProvenanceValue::extracted($examTitle, 0.8) : ProvenanceValue::missing(),
            academicYear: $academicYear ? ProvenanceValue::extracted($academicYear, 0.8) : ProvenanceValue::missing(),
            class: $class ? ProvenanceValue::extracted($class, 0.8) : ProvenanceValue::missing(),
            section: $section ? ProvenanceValue::extracted($section, 0.85) : ProvenanceValue::missing(),
            totalMarks: $totalMarksStr !== null ? ProvenanceValue::extracted((int)$totalMarksStr, 0.9) : ProvenanceValue::missing(),
            scoredMarks: $scoredMarksStr !== null ? ProvenanceValue::extracted((int)$scoredMarksStr, 0.85) : ProvenanceValue::missing(),
            examDate: $examDate ? ProvenanceValue::extracted($examDate, 0.8) : ProvenanceValue::missing(),
            examSubType: $examSubType ? ProvenanceValue::extracted($examSubType, 0.75) : ProvenanceValue::missing(),
            instituteName: $instituteName ? ProvenanceValue::extracted($instituteName, 0.75) : ProvenanceValue::missing(),
        );

        return $metadata;
    }
/**
     * @return array<ExtractedAnswer>
     */
    private function extractAnswers(array $answerSheetPages): array
    {
        $structuredQuestions = [];
        foreach ($answerSheetPages as $page) {
            foreach ($page['questions'] ?? [] as $question) {
                $structuredQuestions[] = [
                    'page' => $page['page'] ?? null,
                    'questionNumber' => $question['questionNumber'] ?? '',
                    'questionText' => $question['questionText'] ?? '',
                    'answerText' => $question['answerText'] ?? '',
                    'marks' => $question['marks'] ?? null,
                ];
            }
        }

        if (!empty($structuredQuestions)) {
            $answers = [];
            foreach ($structuredQuestions as $sq) {
                $answerText = trim($sq['answerText'] ?? '');
                $sourcePage = $sq['page'];

                $answers[] = new ExtractedAnswer(
                    questionNumber: (string)$sq['questionNumber'],
                    answerText: $answerText,
                    confidence: 0.9,
                    sourcePage: $sourcePage !== null ? (string)$sourcePage : null,
                );
            }
            return $answers;
        }

        $fullText = '';
        foreach ($answerSheetPages as $page) {
            $fullText .= "\n--- PAGE {$page['page']} ---\n" . $page['text'];
        }

        // Question patterns - handle "Question N (X marks)" format
        $questionPatterns = [
            // Pattern: "Question N (X marks)" or "Question N:" or "QN:" format
            '/(?:Question\s*(\d+)[a-z]?\s*(?:\([^)]+\))?|Q(\d+)[a-z]?)\s*[:\-\.]?\s*([^\n]+)/i',
            // Pattern: "Question 1:" or "Q1:" format
            '/(?:Question\s*(\d+)[a-z]?)\s*[:\-\.]?\s*([^\n]+)/i',
            // Pattern: "Q1:" or "Q1:" format
            '/(?:Q|Question)\.?\s*(\d+[a-z]?)\s*[:\-\.]?\s*([^\n]+)/i',
        ];

        $questions = [];
        foreach ($questionPatterns as $pattern) {
            if (preg_match_all($pattern, $fullText, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (isset($match[1])) {
                        $num = $match[1];
                        $text = isset($match[2]) ? trim($match[2]) : '';
                        if ($text) {
                            $questions[$match[1]] = ['number' => $num, 'text' => $text];
                        }
                    }
                }
            }
        }

        // If no questions found, try a more general approach
        if (empty($questions)) {
            // Try to find numbered questions with answers
            $questionPattern = '/(?:Question\s*(\d+)[a-z]?|Q(\d+)[a-z]?)\s*[:\-\.]?\s*([^\n]+)/i';
            if (preg_match_all($questionPattern, $fullText, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $num = $match[1] ?? $match[2] ?? '';
                    $text = trim($match[3] ?? $match[1] ?? '');
                    if ($num && $num !== '' && strlen(trim($text)) > 0) {
                        $questions[$num] = ['number' => $num, 'text' => $text];
                    }
                }
            }
        }

        // Now extract answers for each question
        $answers = [];
        
        // Extract the full text for answer extraction
        $fullText = $fullText;
        
        // Find all answers - they come after "Answer / Working:" or "Answer:" or "Answer:"
        // and end before the next question or end of document
        $answerPattern = '/(?:Answer\s*[\/]\s*Working|Answer\s*[:\-])\s*:?\s*([\s\S]*?)(?=(?:Question\s*\d+|Q\d+|$))/im';
        
        // Also handle "Answer:" format
        $answerPattern2 = '/(?:^|\n)\s*Answer\s*[:\-]\s*([\s\S]*?)(?=\n\s*(?:Question\s*\d+|Q\d+|$))/im';
        
        $answerMatches = [];
        foreach ([$answerPattern, $answerPattern2] as $pattern) {
            if (preg_match_all($pattern, $fullText, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (isset($match[1])) {
                        $answerText = trim($match[1]);
                        if (strlen($answerText) > 0) {
                            $answerMatches[] = $answerText;
                        }
                    }
                }
            }
        }
        
        // Map answers to questions by proximity
        $answers = [];
        $questionNumbers = array_keys($questions);
        $sourcePage = null;
        
        for ($i = 0; $i < count($answerMatches); $i++) {
            $questionNum = $i + 1;
            if (isset($questions[$questionNumbers[$i] ?? $i + 1])) {
                $answerText = $answerMatches[$i];
            } else {
                $answerText = $answerMatches[$i] ?? '';
            }
            
$answers[] = new ExtractedAnswer(
                    questionNumber: (string)$questionNum,
                    answerText: $answerText,
                    confidence: 0.8,
                    sourcePage: $sourcePage !== null ? (string)$sourcePage : null,
                );
        }
        
        // If we have more questions than answers, add empty answers for remaining questions
        $totalQuestions = count($questions);
        if ($totalQuestions > count($answers)) {
            for ($i = count($answers); $i < $totalQuestions; $i++) {
                $answers[] = new ExtractedAnswer(
                    questionNumber: (string)($i + 1),
                    answerText: '',
                    confidence: 0.3,
                );
            }
        }
        
        return $answers;
    }

    private function findSourcePageForAnswer(string $fullText, string $answerText): ?int
    {
        $searchSnippet = substr($answerText, 0, 100);
        $pagePattern = '/--- PAGE (\d+) ---/';
        preg_match_all($pagePattern, $fullText, $pageMatches, PREG_OFFSET_CAPTURE);
        
        $answerPos = stripos($fullText, $answerText);
        if ($answerPos === false) {
            return null;
        }

        $nearestPage = null;
        $nearestDistance = PHP_INT_MAX;
        
        foreach ($pageMatches[1] as $index => $match) {
            $pageNum = (int)$match[0];
            $pagePos = $match[1];
            $distance = abs($pagePos - $answerPos);
            if ($distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearestPage = $pageNum;
            }
        }
        
        return $nearestPage;
    }

    private function calculateConfidence(ExtractedAnswerSheetMetadata $metadata, array $answers, int $textLength): float
    {
        $metadataFields = $this->countMetadataFields($metadata);
        $totalPossibleFields = 11; // studentName, rollNumber, subject, examTitle, academicYear, class, section, totalMarks, scoredMarks, examDate, examSubType
        
        $metadataScore = $totalPossibleFields > 0 ? $metadataFields / $totalPossibleFields : 0;
        $answerScore = count($answers) > 0 ? min(1.0, count($answers) / 10) : 0;
        $textScore = min(1.0, $textLength / 5000);
        
        // Weighted average
        return round(($metadataScore * 0.4) + ($answerScore * 0.4) + ($textScore * 0.2), 2);
    }

    private function determineExtractionResult(ExtractedAnswerSheetMetadata $metadata, array $answers, int $textLength): string
    {
        if ($textLength < 50) {
            return 'NO_TEXT_DETECTED';
        }
        
        if (count($answers) === 0 && $this->countMetadataFields($metadata) === 0) {
            return 'OCR_REQUIRED'; // Text exists but no structured data extracted - likely scanned/image PDF
        }
        
        if (count($answers) === 0 && $this->countMetadataFields($metadata) > 0) {
            return 'PARTIAL_METADATA_ONLY';
        }
        
        if (count($answers) > 0 && $this->countMetadataFields($metadata) > 0) {
            return 'SUCCESS';
        }
        
        if (count($answers) > 0) {
            return 'PARTIAL_ANSWERS_ONLY';
        }
        
        return 'PARTIAL';
    }

    public function countMetadataFields(ExtractedAnswerSheetMetadata $metadata): int
    {
        $count = 0;
        $fields = [
            'studentName', 'rollNumber', 'subject', 'examTitle', 
            'academicYear', 'class', 'section', 'totalMarks', 
            'scoredMarks', 'examDate', 'examSubType', 'instituteName'
        ];
        
        foreach ($fields as $field) {
            $value = $metadata->$field;
            if ($value !== null && $value->isAvailable()) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Determine if the extraction result contains meaningful content
     * (not just question headers, marks, or empty answers)
     */
    public function isExtractionUsable(array $extractionResult): bool
    {
        $answers = $extractionResult['answers'] ?? [];
        $metadata = $extractionResult['metadata'] ?? null;
        
        // Check for meaningful answers
        $meaningfulAnswers = 0;
        foreach ($answers as $answer) {
            $answerText = trim($answer->answerText ?? '');
            // Skip empty, whitespace-only, or placeholder answers
            if ($answerText === '' || $this->isPlaceholderText($answerText)) {
                continue;
            }
            $meaningfulAnswers++;
        }
        
        // Check for meaningful metadata
        $metadataFields = 0;
        if ($metadata !== null) {
            $metadataFields = $this->countMetadataFields($metadata);
        }
        
        // Usable if:
        // - At least 1 meaningful answer, OR
        // - Meaningful metadata (>= 3 fields) AND at least some answer content
        // - Question paper with questions detected
        if ($meaningfulAnswers >= 1) {
            return true;
        }
        
        if ($metadataFields >= 3 && count($answers) > 0) {
            // Has metadata and some answer structure (even if answers are empty)
            // This might be a structured form with blank answers
            return true;
        }
        
        return false;
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
        
        foreach ($placeholders as $pattern) {
            if (preg_match($pattern, trim($text))) {
                return true;
            }
        }
        return false;
    }
}