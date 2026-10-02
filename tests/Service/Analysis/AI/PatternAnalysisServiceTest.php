<?php
declare(strict_types=1);

namespace App\Tests\Service\Analysis\AI;

use App\Document\Analysis;
use App\Document\Documents;
use App\Document\DocumentFile;
use App\Document\Exam;
use App\Document\Processing;
use App\Service\Analysis\AI\PatternAnalysisService;
use App\Service\Analysis\AI\QuestionEvaluationResult;
use App\Service\Analysis\AI\ResultStatus;
use App\Service\Analysis\AI\ErrorType;
use App\Service\Analysis\AI\Difficulty;
use App\Service\Analysis\AI\Misconception;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

class PatternAnalysisServiceTest extends TestCase
{
    private PatternAnalysisService $service;

    protected function setUp(): void
    {
        $this->service = new PatternAnalysisService(new NullLogger());
    }

    public function testAnalyzePatternsDetectsRepeatedErrorTypes(): void
    {
        $analysis = $this->createAnalysisWithQuestions(3);

        $results = [
            $this->createResult('1', ResultStatus::Incorrect, ErrorType::Conceptual, Difficulty::Medium),
            $this->createResult('2', ResultStatus::Incorrect, ErrorType::Conceptual, Difficulty::Medium),
            $this->createResult('3', ResultStatus::Correct, ErrorType::None, Difficulty::Easy),
        ];

        $mistakes = [
            ['questionNumber' => '1', 'mistakeType' => 'CONCEPTUAL', 'description' => 'Error 1', 'confidence' => 0.9],
            ['questionNumber' => '2', 'mistakeType' => 'CONCEPTUAL', 'description' => 'Error 2', 'confidence' => 0.85],
        ];

        $patterns = $this->service->analyzePatterns($analysis, $results, $mistakes);

        $repeatedErrors = array_filter($patterns, fn($p) => $p['type'] === 'REPEATED_ERROR_TYPE');
        $this->assertCount(1, $repeatedErrors);
        $repeatedError = array_values($repeatedErrors)[0];
        $this->assertSame('CONCEPTUAL', $repeatedError['category']);
        $this->assertSame(2, $repeatedError['occurrences']);
    }

    public function testAnalyzePatternsDetectsTopicWeakness(): void
    {
        $analysis = $this->createAnalysisWithQuestions(3);
        
        // Override questions to have specific topics
        $exam = $analysis->getExam();
        $questions = $exam->getExtractedQuestions();
        $questions[0]['topics'] = ['Calculus'];
        $questions[1]['topics'] = ['Calculus'];
        $questions[2]['topics'] = ['Algebra'];
        $exam->setExtractedQuestions($questions);

        $results = [
            $this->createResult('1', ResultStatus::Incorrect, ErrorType::Conceptual, Difficulty::Hard, ['Calculus']),
            $this->createResult('2', ResultStatus::PartiallyCorrect, ErrorType::Calculation, Difficulty::Hard, ['Calculus']),
            $this->createResult('3', ResultStatus::Correct, ErrorType::None, Difficulty::Easy, ['Algebra']),
        ];

        $mistakes = [
            ['questionNumber' => '1', 'mistakeType' => 'CONCEPTUAL', 'description' => 'Error 1', 'confidence' => 0.9],
            ['questionNumber' => '2', 'mistakeType' => 'CALCULATION', 'description' => 'Error 2', 'confidence' => 0.8],
        ];

        $patterns = $this->service->analyzePatterns($analysis, $results, $mistakes);

        $topicWeakness = array_filter($patterns, fn($p) => $p['type'] === 'TOPIC_WEAKNESS');
        $this->assertCount(1, $topicWeakness);
        $weakness = array_values($topicWeakness)[0];
        $this->assertSame('Calculus', $weakness['topic']);
        $this->assertSame(2, $weakness['occurrences']);
    }

    public function testAnalyzePatternsDetectsUnansweredCluster(): void
    {
        $analysis = $this->createAnalysisWithQuestions(5);

        $results = [
            $this->createResult('1', ResultStatus::Unanswered, ErrorType::None, Difficulty::Medium),
            $this->createResult('2', ResultStatus::Unanswered, ErrorType::None, Difficulty::Medium),
            $this->createResult('3', ResultStatus::Unanswered, ErrorType::None, Difficulty::Medium),
            $this->createResult('4', ResultStatus::Correct, ErrorType::None, Difficulty::Easy),
            $this->createResult('5', ResultStatus::Correct, ErrorType::None, Difficulty::Easy),
        ];

        $mistakes = [];

        $patterns = $this->service->analyzePatterns($analysis, $results, $mistakes);

        $unansweredCluster = array_filter($patterns, fn($p) => $p['type'] === 'UNANSWERED_CLUSTER');
        $this->assertCount(1, $unansweredCluster);
        $cluster = array_values($unansweredCluster)[0];
        $this->assertSame(3, $cluster['occurrences']);
    }

    public function testAnalyzePatternsDetectsDifficultyStruggle(): void
    {
        $analysis = $this->createAnalysisWithQuestions(4);

        $results = [
            $this->createResult('1', ResultStatus::Incorrect, ErrorType::Conceptual, Difficulty::Hard),
            $this->createResult('2', ResultStatus::Incorrect, ErrorType::Procedural, Difficulty::Hard),
            $this->createResult('3', ResultStatus::Correct, ErrorType::None, Difficulty::Easy),
            $this->createResult('4', ResultStatus::Correct, ErrorType::None, Difficulty::Easy),
        ];

        $mistakes = [
            ['questionNumber' => '1', 'mistakeType' => 'CONCEPTUAL', 'description' => 'Error 1', 'confidence' => 0.9],
            ['questionNumber' => '2', 'mistakeType' => 'PROCEDURAL', 'description' => 'Error 2', 'confidence' => 0.85],
        ];

        $patterns = $this->service->analyzePatterns($analysis, $results, $mistakes);

        $difficultyStruggle = array_filter($patterns, fn($p) => $p['type'] === 'DIFFICULTY_STRUGGLE');
        $this->assertCount(1, $difficultyStruggle);
        $struggle = array_values($difficultyStruggle)[0];
        $this->assertSame('hard', $struggle['topic']);
    }

    public function testAnalyzePatternsReturnsEmptyArrayForGoodPerformance(): void
    {
        $analysis = $this->createAnalysisWithQuestions(4);

        $results = [
            $this->createResult('1', ResultStatus::Correct, ErrorType::None, Difficulty::Easy),
            $this->createResult('2', ResultStatus::Correct, ErrorType::None, Difficulty::Easy),
            $this->createResult('3', ResultStatus::Correct, ErrorType::None, Difficulty::Medium),
            $this->createResult('4', ResultStatus::Correct, ErrorType::None, Difficulty::Medium),
        ];

        $mistakes = [];

        $patterns = $this->service->analyzePatterns($analysis, $results, $mistakes);

        $this->assertCount(0, $patterns);
    }

    private function createResult(
        string $questionNumber,
        ResultStatus $status,
        ErrorType $errorType,
        Difficulty $difficulty,
        array $topics = ['Test']
    ): QuestionEvaluationResult {
        return new QuestionEvaluationResult(
            questionNumber: $questionNumber,
            resultStatus: $status,
            marksAwarded: $status === ResultStatus::Correct ? 5 : ($status === ResultStatus::PartiallyCorrect ? 3 : 0),
            maxMarks: 5,
            answerQuality: $status === ResultStatus::Correct ? 1.0 : 0.5,
            reasoningQuality: $status === ResultStatus::Correct ? 1.0 : 0.5,
            confidence: 0.8,
            errorType: $errorType,
            errorDescription: 'Test error',
            difficulty: $difficulty,
            difficultyConfidence: 0.8,
            topics: array_map(fn($t) => ['name' => $t, 'confidence' => 0.8], $topics),
            concepts: [],
            misconception: Misconception::notIdentified(),
        );
    }

    private function createAnalysisWithQuestions(int $count): Analysis
    {
        $analysis = new Analysis();
        $analysis->setStudentId('test-student');
        $analysis->setSource('EXTERNAL_UPLOAD');
        $analysis->setStatus('CONFIRMED');

        $documents = new Documents();
        $documents->setQuestionPaper(new DocumentFile());
        $documents->setAnswerSheet(new DocumentFile());
        $analysis->setDocuments($documents);

        $exam = new Exam();
        $exam->setTitle('Test Exam');
        $exam->setSubject('Mathematics');
        $exam->setTotalMarks($count * 5);

        $questions = [];
        for ($i = 1; $i <= $count; $i++) {
            $questions[] = [
                'questionNumber' => ['value' => (string)$i, 'status' => 'extracted', 'confidence' => 0.95],
                'questionText' => ['value' => "Question {$i} text", 'status' => 'extracted', 'confidence' => 0.9],
                'marks' => ['value' => 5, 'status' => 'extracted', 'confidence' => 0.85],
                'type' => ['value' => 'SHORT_ANSWER', 'status' => 'inferred', 'confidence' => 0.7],
                'source' => ['page' => 1, 'bbox' => null],
                'answerText' => ['value' => "Answer {$i}", 'status' => 'extracted', 'confidence' => 0.8],
                'answerSource' => ['page' => 1, 'bbox' => null],
                'topics' => ['Test'],
                'concepts' => ['Test'],
            ];
        }
        $exam->setExtractedQuestions($questions);
        $analysis->setExam($exam);

        $processing = new Processing();
        $analysis->setProcessing($processing);

        return $analysis;
    }
}