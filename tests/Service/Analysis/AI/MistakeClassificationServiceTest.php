<?php
declare(strict_types=1);

namespace App\Tests\Service\Analysis\AI;

use App\Document\Analysis;
use App\Document\Documents;
use App\Document\DocumentFile;
use App\Document\Exam;
use App\Document\Processing;
use App\Service\Analysis\AI\MistakeClassificationService;
use App\Service\Analysis\AI\OpenAiProvider;
use App\Service\Analysis\AI\QuestionEvaluationResult;
use App\Service\Analysis\AI\ResultStatus;
use App\Service\Analysis\AI\ErrorType;
use App\Service\Analysis\AI\Difficulty;
use App\Service\Analysis\AI\Misconception;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

class MistakeClassificationServiceTest extends TestCase
{
    private MistakeClassificationService $service;
    private OpenAiProvider $mockProvider;

    protected function setUp(): void
    {
        $this->mockProvider = $this->createMock(OpenAiProvider::class);
        $this->service = new MistakeClassificationService($this->mockProvider, new NullLogger());
    }

    public function testClassifyMistakesReturnsMistakesForIncorrectAnswers(): void
    {
        $analysis = $this->createAnalysisWithQuestions(2);

        $results = [
            new QuestionEvaluationResult(
                questionNumber: '1',
                resultStatus: ResultStatus::Incorrect,
                marksAwarded: 0,
                maxMarks: 5,
                answerQuality: 0.2,
                reasoningQuality: 0.3,
                confidence: 0.85,
                errorType: ErrorType::Conceptual,
                errorDescription: 'Misunderstood the concept of derivatives',
                difficulty: Difficulty::Hard,
                difficultyConfidence: 0.9,
                topics: [['name' => 'Calculus', 'confidence' => 0.9]],
                concepts: [['name' => 'Derivatives', 'confidence' => 0.9]],
                misconception: Misconception::identified('Confuses derivative with integral', 0.8),
            ),
            new QuestionEvaluationResult(
                questionNumber: '2',
                resultStatus: ResultStatus::PartiallyCorrect,
                marksAwarded: 2,
                maxMarks: 5,
                answerQuality: 0.5,
                reasoningQuality: 0.6,
                confidence: 0.75,
                errorType: ErrorType::Calculation,
                errorDescription: 'Arithmetic error in final step',
                difficulty: Difficulty::Medium,
                difficultyConfidence: 0.8,
                topics: [['name' => 'Algebra', 'confidence' => 0.8]],
                concepts: [['name' => 'Equations', 'confidence' => 0.8]],
                misconception: Misconception::notIdentified(),
            ),
        ];

        $mistakes = $this->service->classifyMistakes($analysis, $results);

        $this->assertCount(2, $mistakes);
        
        $this->assertSame('1', $mistakes[0]['questionNumber']);
        $this->assertSame('CONCEPTUAL', $mistakes[0]['mistakeType']);
        $this->assertStringContainsString('derivatives', $mistakes[0]['description']);
        $this->assertArrayHasKey('misconception', $mistakes[0]);
        $this->assertSame('Confuses derivative with integral', $mistakes[0]['misconception']['description']);

        $this->assertSame('2', $mistakes[1]['questionNumber']);
        $this->assertSame('CALCULATION', $mistakes[1]['mistakeType']);
        $this->assertStringContainsString('Arithmetic', $mistakes[1]['description']);
        $this->assertArrayNotHasKey('misconception', $mistakes[1]);
    }

    public function testClassifyMistakesSkipsCorrectAndUnanswered(): void
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
                difficulty: Difficulty::Easy,
                difficultyConfidence: 0.9,
                topics: [],
                concepts: [],
                misconception: Misconception::notIdentified(),
            ),
            new QuestionEvaluationResult(
                questionNumber: '2',
                resultStatus: ResultStatus::Unanswered,
                marksAwarded: 0,
                maxMarks: 5,
                answerQuality: 0.0,
                reasoningQuality: 0.0,
                confidence: 1.0,
                errorType: ErrorType::None,
                errorDescription: null,
                difficulty: Difficulty::Easy,
                difficultyConfidence: 0.9,
                topics: [],
                concepts: [],
                misconception: Misconception::notIdentified(),
            ),
            new QuestionEvaluationResult(
                questionNumber: '3',
                resultStatus: ResultStatus::Incorrect,
                marksAwarded: 0,
                maxMarks: 5,
                answerQuality: 0.1,
                reasoningQuality: 0.2,
                confidence: 0.8,
                errorType: ErrorType::Procedural,
                errorDescription: 'Wrong procedure',
                difficulty: Difficulty::Medium,
                difficultyConfidence: 0.8,
                topics: [['name' => 'Geometry', 'confidence' => 0.8]],
                concepts: [['name' => 'Area', 'confidence' => 0.8]],
                misconception: Misconception::notIdentified(),
            ),
        ];

        $mistakes = $this->service->classifyMistakes($analysis, $results);

        $this->assertCount(1, $mistakes);
        $this->assertSame('3', $mistakes[0]['questionNumber']);
        $this->assertSame('PROCEDURAL', $mistakes[0]['mistakeType']);
    }

    public function testClassifyMistakesReturnsEmptyArrayForAllCorrect(): void
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
                difficulty: Difficulty::Easy,
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
                difficulty: Difficulty::Easy,
                difficultyConfidence: 0.9,
                topics: [],
                concepts: [],
                misconception: Misconception::notIdentified(),
            ),
        ];

        $mistakes = $this->service->classifyMistakes($analysis, $results);

        $this->assertCount(0, $mistakes);
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