<?php
declare(strict_types=1);

namespace App\Tests\Service\Analysis\AI;

use App\Document\Analysis;
    use App\Document\Documents;
    use App\Document\DocumentFile;
    use App\Document\Exam;
    use App\Document\Processing;
    use App\Service\Analysis\AI\QuestionEvaluationService;
    use App\Service\Analysis\AI\OpenAiProvider;
    use App\Service\Analysis\AI\AuditedAiProvider;
    use App\Service\Analysis\AI\QuestionEvaluationRequest;
    use App\Service\Analysis\AI\QuestionEvaluationResult;
    use App\Service\Analysis\AI\ResultStatus;
    use App\Service\Analysis\AI\ErrorType;
    use App\Service\Analysis\AI\Difficulty;
    use App\Service\Analysis\AI\Misconception;
    use Psr\Log\NullLogger;
    use PHPUnit\Framework\TestCase;

class QuestionEvaluationServiceTest extends TestCase
{
    private QuestionEvaluationService $service;
    private OpenAiProvider $mockProvider;
    private AuditedAiProvider $mockAuditedProvider;

    protected function setUp(): void
    {
        $this->mockProvider = $this->createMock(OpenAiProvider::class);
        $this->mockAuditedProvider = $this->createMock(AuditedAiProvider::class);
        $this->mockAuditedProvider->method('getProvider')->willReturn($this->mockProvider);
        $this->service = new QuestionEvaluationService($this->mockAuditedProvider, new NullLogger());
    }

    public function testEvaluateQuestionsReturnsResultsForAllQuestions(): void
    {
        $analysis = $this->createAnalysisWithQuestions(2);

        $result1 = new QuestionEvaluationResult(
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
            topics: [['name' => 'Algebra', 'confidence' => 0.9]],
            concepts: [['name' => 'Equations', 'confidence' => 0.9]],
            misconception: Misconception::notIdentified(),
        );

        $result2 = new QuestionEvaluationResult(
            questionNumber: '2',
            resultStatus: ResultStatus::PartiallyCorrect,
            marksAwarded: 3,
            maxMarks: 5,
            answerQuality: 0.6,
            reasoningQuality: 0.7,
            confidence: 0.8,
            errorType: ErrorType::Calculation,
            errorDescription: 'Minor arithmetic error',
            difficulty: Difficulty::Medium,
            difficultyConfidence: 0.85,
            topics: [['name' => 'Algebra', 'confidence' => 0.8]],
            concepts: [['name' => 'Equations', 'confidence' => 0.8]],
            misconception: Misconception::notIdentified(),
        );

        $this->mockAuditedProvider
            ->method('evaluateQuestion')
            ->willReturnOnConsecutiveCalls(
                ['result' => $result1, 'piiStripped' => []],
                ['result' => $result2, 'piiStripped' => []]
            );

        $results = $this->service->evaluateQuestions($analysis);

        $this->assertCount(2, $results);
        $this->assertSame('1', $results[0]->questionNumber);
        $this->assertSame('2', $results[1]->questionNumber);
        $this->assertSame(ResultStatus::Correct, $results[0]->resultStatus);
        $this->assertSame(ResultStatus::PartiallyCorrect, $results[1]->resultStatus);
    }

    public function testEvaluateQuestionsCallsProviderWithCorrectParameters(): void
    {
        $analysis = $this->createAnalysisWithQuestions(1);

        $expectedResult = new QuestionEvaluationResult(
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
            topics: [['name' => 'Algebra', 'confidence' => 0.9]],
            concepts: [['name' => 'Equations', 'confidence' => 0.9]],
            misconception: Misconception::notIdentified(),
        );

        $this->mockAuditedProvider
            ->method('evaluateQuestion')
            ->willReturn(['result' => $expectedResult, 'piiStripped' => []]);

        $results = $this->service->evaluateQuestions($analysis);

        $this->assertCount(1, $results);
    }

    public function testGetAiProviderReturnsProvider(): void
    {
        $this->assertSame($this->mockProvider, $this->service->getAiProvider());
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
                'topics' => ['Algebra'],
                'concepts' => ['Equations'],
            ];
        }
        $exam->setExtractedQuestions($questions);
        $analysis->setExam($exam);

        $processing = new Processing();
        $analysis->setProcessing($processing);

        return $analysis;
    }
}