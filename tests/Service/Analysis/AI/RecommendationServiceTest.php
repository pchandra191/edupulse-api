<?php
declare(strict_types=1);

namespace App\Tests\Service\Analysis\AI;

use App\Document\Analysis;
use App\Document\Documents;
use App\Document\DocumentFile;
use App\Document\Exam;
use App\Document\Processing;
use App\Service\Analysis\AI\RecommendationService;
use App\Service\Analysis\AI\QuestionEvaluationResult;
use App\Service\Analysis\AI\ResultStatus;
use App\Service\Analysis\AI\ErrorType;
use App\Service\Analysis\AI\Difficulty;
use App\Service\Analysis\AI\Misconception;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

class RecommendationServiceTest extends TestCase
{
    private RecommendationService $service;

    protected function setUp(): void
    {
        $this->service = new RecommendationService(new NullLogger());
    }

    public function testGenerateRecommendationsForLowOverallScore(): void
    {
        $analysis = $this->createAnalysisWithQuestions(3);

        $results = [
            $this->createResult('1', ResultStatus::Incorrect, ErrorType::Conceptual, Difficulty::Medium, 0, 5),
            $this->createResult('2', ResultStatus::Incorrect, ErrorType::Calculation, Difficulty::Medium, 0, 5),
            $this->createResult('3', ResultStatus::Incorrect, ErrorType::Procedural, Difficulty::Medium, 0, 5),
        ];

        $mistakes = [
            ['questionNumber' => '1', 'mistakeType' => 'CONCEPTUAL', 'description' => 'Error 1', 'confidence' => 0.9],
            ['questionNumber' => '2', 'mistakeType' => 'CALCULATION', 'description' => 'Error 2', 'confidence' => 0.8],
            ['questionNumber' => '3', 'mistakeType' => 'PROCEDURAL', 'description' => 'Error 3', 'confidence' => 0.7],
        ];

        $topicClassifications = [
            ['topic' => 'Algebra', 'confidence' => 0.8, 'questions' => ['1', '2', '3'], 'masteryLevel' => 'STRUGGLING'],
        ];

        $patterns = [
            ['type' => 'REPEATED_ERROR_TYPE', 'category' => 'CONCEPTUAL', 'topic' => 'general', 'occurrences' => 2, 'confidence' => 0.7],
        ];

        $recommendations = $this->service->generateRecommendations($analysis, $results, $mistakes, $topicClassifications, $patterns);

        $this->assertGreaterThan(0, count($recommendations));
        
        // Check for HIGH priority recommendation for low score
        $highPriority = array_filter($recommendations, fn($r) => $r['priority'] === 'HIGH');
        $this->assertGreaterThan(0, count($highPriority));
    }

    public function testGenerateRecommendationsForTopicWeakness(): void
    {
        $analysis = $this->createAnalysisWithQuestions(3);

        $results = [
            $this->createResult('1', ResultStatus::Incorrect, ErrorType::Conceptual, Difficulty::Medium, 0, 5, ['Algebra']),
            $this->createResult('2', ResultStatus::Incorrect, ErrorType::Calculation, Difficulty::Medium, 0, 5, ['Algebra']),
            $this->createResult('3', ResultStatus::Correct, ErrorType::None, Difficulty::Easy, 5, 5, ['Geometry']),
        ];

        $mistakes = [
            ['questionNumber' => '1', 'mistakeType' => 'CONCEPTUAL', 'description' => 'Error 1', 'confidence' => 0.9],
            ['questionNumber' => '2', 'mistakeType' => 'CALCULATION', 'description' => 'Error 2', 'confidence' => 0.8],
        ];

        $topicClassifications = [
            ['topic' => 'Algebra', 'confidence' => 0.8, 'questions' => ['1', '2'], 'masteryLevel' => 'STRUGGLING'],
            ['topic' => 'Geometry', 'confidence' => 0.9, 'questions' => ['3'], 'masteryLevel' => 'MASTERED'],
        ];

        $patterns = [];

        $recommendations = $this->service->generateRecommendations($analysis, $results, $mistakes, $topicClassifications, $patterns);

        $algebraRecs = array_filter($recommendations, fn($r) => str_contains($r['action'], 'Algebra'));
        $this->assertGreaterThan(0, count($algebraRecs));
    }

    public function testGenerateRecommendationsForUnansweredQuestions(): void
    {
        $analysis = $this->createAnalysisWithQuestions(5);

        $results = [
            $this->createResult('1', ResultStatus::Unanswered, ErrorType::None, Difficulty::Medium, 0, 5),
            $this->createResult('2', ResultStatus::Unanswered, ErrorType::None, Difficulty::Medium, 0, 5),
            $this->createResult('3', ResultStatus::Unanswered, ErrorType::None, Difficulty::Medium, 0, 5),
            $this->createResult('4', ResultStatus::Correct, ErrorType::None, Difficulty::Easy, 5, 5),
            $this->createResult('5', ResultStatus::Correct, ErrorType::None, Difficulty::Easy, 5, 5),
        ];

        $mistakes = [];
        $topicClassifications = [];
        $patterns = [];

        $recommendations = $this->service->generateRecommendations($analysis, $results, $mistakes, $topicClassifications, $patterns);

        $timeMgmtRecs = array_filter($recommendations, fn($r) => str_contains($r['action'], 'time management'));
        $this->assertGreaterThan(0, count($timeMgmtRecs));
    }

    public function testGenerateRecommendationsDeduplicatesByAction(): void
    {
        $analysis = $this->createAnalysisWithQuestions(2);

        $results = [
            $this->createResult('1', ResultStatus::Incorrect, ErrorType::Conceptual, Difficulty::Medium, 0, 5, ['Algebra']),
            $this->createResult('2', ResultStatus::Incorrect, ErrorType::Conceptual, Difficulty::Medium, 0, 5, ['Algebra']),
        ];

        $mistakes = [
            ['questionNumber' => '1', 'mistakeType' => 'CONCEPTUAL', 'description' => 'Error 1', 'confidence' => 0.9],
            ['questionNumber' => '2', 'mistakeType' => 'CONCEPTUAL', 'description' => 'Error 2', 'confidence' => 0.8],
        ];

        $topicClassifications = [
            ['topic' => 'Algebra', 'confidence' => 0.8, 'questions' => ['1', '2'], 'masteryLevel' => 'STRUGGLING'],
        ];

        $patterns = [
            ['type' => 'REPEATED_ERROR_TYPE', 'category' => 'CONCEPTUAL', 'topic' => 'general', 'occurrences' => 2, 'confidence' => 0.7],
            ['type' => 'TOPIC_WEAKNESS', 'category' => 'knowledge_gap', 'topic' => 'Algebra', 'occurrences' => 2, 'confidence' => 0.75],
        ];

        $recommendations = $this->service->generateRecommendations($analysis, $results, $mistakes, $topicClassifications, $patterns);

        // Should have limited recommendations (max 10) and deduplicated
        $this->assertLessThanOrEqual(10, count($recommendations));
        
        // Check that repeated error type and topic weakness don't produce duplicate actions
        $actions = array_column($recommendations, 'action');
        $uniqueActions = array_unique($actions);
        $this->assertSame(count($actions), count($uniqueActions));
    }

    public function testGenerateRecommendationsSortedByPriority(): void
    {
        $analysis = $this->createAnalysisWithQuestions(1);

        $results = [
            $this->createResult('1', ResultStatus::Incorrect, ErrorType::Calculation, Difficulty::Medium, 0, 5),
        ];

        $mistakes = [
            ['questionNumber' => '1', 'mistakeType' => 'CALCULATION', 'description' => 'Error 1', 'confidence' => 0.9],
        ];

        $topicClassifications = [];
        $patterns = [
            ['type' => 'TOPIC_WEAKNESS', 'category' => 'knowledge_gap', 'topic' => 'Algebra', 'occurrences' => 1, 'confidence' => 0.7],
        ];

        $recommendations = $this->service->generateRecommendations($analysis, $results, $mistakes, $topicClassifications, $patterns);

        // Should be sorted by priority (HIGH first)
        $this->assertGreaterThan(0, count($recommendations));
        
        // Check that priorities are in descending order (HIGH > MEDIUM > LOW)
        $priorities = array_column($recommendations, 'priority');
        $priorityOrder = ['HIGH' => 3, 'MEDIUM' => 2, 'LOW' => 1];
        $prevPriority = 4; // Higher than any possible value
        
        foreach ($priorities as $priority) {
            $this->assertLessThanOrEqual($prevPriority, $priorityOrder[$priority] ?? 0);
            $prevPriority = $priorityOrder[$priority] ?? 0;
        }
    }

    private function createResult(
        string $questionNumber,
        ResultStatus $status,
        ErrorType $errorType,
        Difficulty $difficulty,
        int $marksAwarded,
        int $maxMarks,
        array $topics = ['Test']
    ): QuestionEvaluationResult {
        return new QuestionEvaluationResult(
            questionNumber: $questionNumber,
            resultStatus: $status,
            marksAwarded: $marksAwarded,
            maxMarks: $maxMarks,
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
                'questionId' => 'q' . $i,
                'questionNumber' => (string)$i,
                'questionText' => "Question {$i} text",
                'type' => 'SHORT_ANSWER',
                'marks' => 5,
                'options' => [],
                'studentAnswer' => "Answer {$i}",
                'topics' => ['Test'],
                'concepts' => ['Test'],
            ];
        }
        $exam->setQuestions($questions);

        $processing = new Processing();
        $analysis->setProcessing($processing);

        return $analysis;
    }
}