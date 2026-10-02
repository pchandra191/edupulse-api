<?php
declare(strict_types=1);

namespace App\Tests\Service\Analysis\AI;

use App\Document\Analysis;
use App\Document\Documents;
use App\Document\DocumentFile;
use App\Document\Exam;
use App\Document\Processing;
use App\Service\Analysis\AI\TopicClassificationService;
use App\Service\Analysis\AI\OpenAiProvider;
use App\Service\Analysis\AI\QuestionEvaluationResult;
use App\Service\Analysis\AI\ResultStatus;
use App\Service\Analysis\AI\ErrorType;
use App\Service\Analysis\AI\Difficulty;
use App\Service\Analysis\AI\Misconception;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

class TopicClassificationServiceTest extends TestCase
{
    private TopicClassificationService $service;
    private OpenAiProvider $mockProvider;

    protected function setUp(): void
    {
        $this->mockProvider = $this->createMock(OpenAiProvider::class);
        $this->service = new TopicClassificationService($this->mockProvider, new NullLogger());
    }

    public function testClassifyTopicsAggregatesTopicsFromEvaluationResults(): void
    {
        $analysis = $this->createAnalysisWithQuestions(3);

        $results = [
            new QuestionEvaluationResult(
                questionNumber: '1',
                resultStatus: ResultStatus::Correct,
                marksAwarded: 5,
                maxMarks: 5,
                answerQuality: 1.0,
                reasoningQuality: 1.0,
                confidence: 0.95,
                errorType: ErrorType::None,
                errorDescription: null,
                difficulty: Difficulty::Medium,
                difficultyConfidence: 0.9,
                topics: [
                    ['name' => 'Algebra', 'confidence' => 0.9],
                    ['name' => 'Equations', 'confidence' => 0.8],
                ],
                concepts: [],
                misconception: Misconception::notIdentified(),
            ),
            new QuestionEvaluationResult(
                questionNumber: '2',
                resultStatus: ResultStatus::PartiallyCorrect,
                marksAwarded: 3,
                maxMarks: 5,
                answerQuality: 0.6,
                reasoningQuality: 0.7,
                confidence: 0.8,
                errorType: ErrorType::Calculation,
                errorDescription: 'Calculation error',
                difficulty: Difficulty::Medium,
                difficultyConfidence: 0.85,
                topics: [
                    ['name' => 'Algebra', 'confidence' => 0.85],
                    ['name' => 'Functions', 'confidence' => 0.7],
                ],
                concepts: [],
                misconception: Misconception::notIdentified(),
            ),
            new QuestionEvaluationResult(
                questionNumber: '3',
                resultStatus: ResultStatus::Incorrect,
                marksAwarded: 0,
                maxMarks: 5,
                answerQuality: 0.2,
                reasoningQuality: 0.3,
                confidence: 0.75,
                errorType: ErrorType::Conceptual,
                errorDescription: 'Conceptual error',
                difficulty: Difficulty::Hard,
                difficultyConfidence: 0.8,
                topics: [
                    ['name' => 'Calculus', 'confidence' => 0.9],
                ],
                concepts: [],
                misconception: Misconception::notIdentified(),
            ),
        ];

        $topics = $this->service->classifyTopics($analysis, $results);

        $this->assertCount(4, $topics);

        // Check Algebra appears with averaged confidence
        $algebra = array_filter($topics, fn($t) => $t['topic'] === 'Algebra');
        $this->assertCount(1, $algebra);
        $algebra = array_values($algebra)[0];
        $this->assertEqualsWithDelta(0.875, $algebra['confidence'], 0.01); // (0.9 + 0.85) / 2
        $this->assertContains('1', $algebra['questions']);
        $this->assertContains('2', $algebra['questions']);

        // Check Equations
        $equations = array_filter($topics, fn($t) => $t['topic'] === 'Equations');
        $this->assertCount(1, $equations);
        $equations = array_values($equations)[0];
        $this->assertSame(0.8, $equations['confidence']);
        $this->assertContains('1', $equations['questions']);

        // Check Functions
        $functions = array_filter($topics, fn($t) => $t['topic'] === 'Functions');
        $this->assertCount(1, $functions);
        $functions = array_values($functions)[0];
        $this->assertSame(0.7, $functions['confidence']);
        $this->assertContains('2', $functions['questions']);

        // Check Calculus
        $calculus = array_filter($topics, fn($t) => $t['topic'] === 'Calculus');
        $this->assertCount(1, $calculus);
        $calculus = array_values($calculus)[0];
        $this->assertSame(0.9, $calculus['confidence']);
        $this->assertContains('3', $calculus['questions']);
    }

    public function testClassifyTopicsSortsByConfidenceDescending(): void
    {
        $analysis = $this->createAnalysisWithQuestions(2);

        $results = [
            new QuestionEvaluationResult(
                questionNumber: '1',
                resultStatus: ResultStatus::Correct,
                marksAwarded: 5,
                maxMarks: 5,
                answerQuality: 1.0,
                reasoningQuality: 1.0,
                confidence: 0.95,
                errorType: ErrorType::None,
                errorDescription: null,
                difficulty: Difficulty::Medium,
                difficultyConfidence: 0.9,
                topics: [['name' => 'LowConfidence', 'confidence' => 0.5]],
                concepts: [],
                misconception: Misconception::notIdentified(),
            ),
            new QuestionEvaluationResult(
                questionNumber: '2',
                resultStatus: ResultStatus::Correct,
                marksAwarded: 5,
                maxMarks: 5,
                answerQuality: 1.0,
                reasoningQuality: 1.0,
                confidence: 0.95,
                errorType: ErrorType::None,
                errorDescription: null,
                difficulty: Difficulty::Medium,
                difficultyConfidence: 0.9,
                topics: [['name' => 'HighConfidence', 'confidence' => 0.9]],
                concepts: [],
                misconception: Misconception::notIdentified(),
            ),
        ];

        $topics = $this->service->classifyTopics($analysis, $results);

        $this->assertSame('HighConfidence', $topics[0]['topic']);
        $this->assertSame('LowConfidence', $topics[1]['topic']);
    }

    public function testClassifyTopicsCalculatesMasteryLevel(): void
    {
        $analysis = $this->createAnalysisWithQuestions(3);

        $results = [
            new QuestionEvaluationResult(
                questionNumber: '1',
                resultStatus: ResultStatus::Correct,
                marksAwarded: 5,
                maxMarks: 5,
                answerQuality: 1.0,
                reasoningQuality: 1.0,
                confidence: 0.95,
                errorType: ErrorType::None,
                errorDescription: null,
                difficulty: Difficulty::Medium,
                difficultyConfidence: 0.9,
                topics: [['name' => 'Mastered', 'confidence' => 0.9]],
                concepts: [],
                misconception: Misconception::notIdentified(),
            ),
            new QuestionEvaluationResult(
                questionNumber: '2',
                resultStatus: ResultStatus::Correct,
                marksAwarded: 5,
                maxMarks: 5,
                answerQuality: 1.0,
                reasoningQuality: 1.0,
                confidence: 0.95,
                errorType: ErrorType::None,
                errorDescription: null,
                difficulty: Difficulty::Medium,
                difficultyConfidence: 0.9,
                topics: [['name' => 'Mastered', 'confidence' => 0.9]],
                concepts: [],
                misconception: Misconception::notIdentified(),
            ),
            new QuestionEvaluationResult(
                questionNumber: '3',
                resultStatus: ResultStatus::Correct,
                marksAwarded: 5,
                maxMarks: 5,
                answerQuality: 1.0,
                reasoningQuality: 1.0,
                confidence: 0.95,
                errorType: ErrorType::None,
                errorDescription: null,
                difficulty: Difficulty::Medium,
                difficultyConfidence: 0.9,
                topics: [['name' => 'Mastered', 'confidence' => 0.9]],
                concepts: [],
                misconception: Misconception::notIdentified(),
            ),
        ];

        $topics = $this->service->classifyTopics($analysis, $results);
        $mastered = array_values(array_filter($topics, fn($t) => $t['topic'] === 'Mastered'))[0];
        $this->assertSame('MASTERED', $mastered['masteryLevel']);
    }

    public function testClassifyTopicsReturnsEmptyArrayForNoTopics(): void
    {
        $analysis = $this->createAnalysisWithQuestions(2);

        $results = [
            new QuestionEvaluationResult(
                questionNumber: '1',
                resultStatus: ResultStatus::Correct,
                marksAwarded: 5,
                maxMarks: 5,
                answerQuality: 1.0,
                reasoningQuality: 1.0,
                confidence: 0.95,
                errorType: ErrorType::None,
                errorDescription: null,
                difficulty: Difficulty::Medium,
                difficultyConfidence: 0.9,
                topics: [],
                concepts: [],
                misconception: Misconception::notIdentified(),
            ),
            new QuestionEvaluationResult(
                questionNumber: '2',
                resultStatus: ResultStatus::Correct,
                marksAwarded: 5,
                maxMarks: 5,
                answerQuality: 1.0,
                reasoningQuality: 1.0,
                confidence: 0.95,
                errorType: ErrorType::None,
                errorDescription: null,
                difficulty: Difficulty::Medium,
                difficultyConfidence: 0.9,
                topics: [],
                concepts: [],
                misconception: Misconception::notIdentified(),
            ),
        ];

        $topics = $this->service->classifyTopics($analysis, $results);

        $this->assertCount(0, $topics);
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
                'topics' => ['Test Topic'],
                'concepts' => ['Test Concept'],
            ];
        }
        $exam->setExtractedQuestions($questions);
        $analysis->setExam($exam);

        $processing = new Processing();
        $analysis->setProcessing($processing);

        return $analysis;
    }
}