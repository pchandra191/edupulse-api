<?php

declare(strict_types=1);

namespace App\Tests\Service\StudentIntelligence;

use App\Document\Analysis;
use App\Document\Documents;
use App\Document\Exam;
use App\Document\Processing;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use App\Service\StudentIntelligence\StudentIntelligenceService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StudentIntelligenceServiceTest extends TestCase
{
    private function createService(MongoAnalysisRepository $mongoRepo): StudentIntelligenceService
    {
        return new StudentIntelligenceService(
            $mongoRepo,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function createAnalysisDocument(
        string $analysisId,
        string $studentId,
        string $status,
        ?string $subject = null,
        ?array $aiAnalysis = null,
        ?\DateTimeImmutable $completedAt = null,
    ): Analysis {
        $analysis = new Analysis();
        $analysis->setAnalysisId($analysisId);
        $analysis->setStudentId($studentId);
        $analysis->setStatus($status);
        $analysis->setDocuments(new Documents());
        $analysis->setExam(new Exam());
        $analysis->getExam()->setSubject($subject);
        $analysis->setProcessing(new Processing());
        $analysis->getProcessing()->setStatus($status);
        $analysis->getProcessing()->setCompletedAt($completedAt ?? new \DateTimeImmutable());
        $analysis->setAiAnalysis($aiAnalysis);
        return $analysis;
    }

    private function buildDirectAiAnalysis(
        string $subject,
        int $score,
        int $totalMarks,
        float $percentage,
        array $conceptAssessments = [],
        array $strengths = [],
        array $weaknesses = [],
        array $needsAttention = [],
        array $conceptGaps = [],
        array $recommendations = [],
        array $mistakes = [],
        array $questionAnalysis = [],
    ): array {
        return [
            'currentExamAnalysis' => [
                'performance' => [
                    'score' => $score,
                    'totalMarks' => $totalMarks,
                    'percentage' => $percentage,
                ],
                'strengths' => $strengths,
                'weaknesses' => $weaknesses,
                'needsAttention' => $needsAttention,
                'conceptGaps' => $conceptGaps,
                'recommendations' => $recommendations,
                'conceptAssessments' => $conceptAssessments,
                'mistakes' => $mistakes,
                'evidence' => [],
            ],
            'source' => 'OPENAI_DIRECT_ANALYSIS',
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'questionAnalysis' => $questionAnalysis,
        ];
    }

    private function buildConceptAssessment(string $concept, string $status, float $confidence = 0.8): array
    {
        return [
            'concept' => $concept,
            'status' => $status,
            'confidence' => $confidence,
            'evidence' => [],
        ];
    }

    private function buildQuestionAnalysis(
        string $questionNumber,
        string $evaluationStatus,
        string $conceptName = '',
        string $conceptStatus = '',
    ): array {
        return [
            'questionNumber' => $questionNumber,
            'subQuestion' => null,
            'status' => 'evaluated',
            'question' => 'Sample question',
            'studentAnswer' => 'Sample answer',
            'evaluation' => [
                'evaluationStatus' => $evaluationStatus,
                'correctness' => $evaluationStatus === 'CORRECT' ? 'correct' : 'incorrect',
                'marksAwarded' => $evaluationStatus === 'CORRECT' ? 1 : 0,
                'maxMarks' => 1,
                'whatStudentDidWell' => '',
                'mistake' => '',
                'whyItIsWrong' => '',
                'missingPoints' => [],
            ],
            'concept' => $conceptName !== '' ? [
                'name' => $conceptName,
                'status' => $conceptStatus ?: 'STRONG',
                'explanation' => '',
            ] : null,
            'improvement' => [
                'whatToLearn' => '',
                'whatToPractice' => '',
                'recommendedAction' => '',
            ],
            'evidence' => [],
        ];
    }

    // --------------------------------------------------------
    // 1. No completed analyses
    // --------------------------------------------------------
    public function testNoCompletedAnalysesReturnsEmptyIntelligence(): void
    {
        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn([]);

        $service = $this->createService($mongoRepo);
        $result = $service->getIntelligence('student-1');

        $this->assertSame(0, $result['overview']['totalCompletedExams']);
        $this->assertSame(0, $result['overview']['totalSubjects']);
        $this->assertSame(0, $result['overview']['totalQuestions']);
        $this->assertSame(0, $result['overview']['overallAverage']);
        $this->assertNull($result['overview']['latestScore']);
        $this->assertNull($result['overview']['bestScore']);
        $this->assertNull($result['overview']['lowestScore']);
        $this->assertNull($result['overview']['improvementFromPrevious']);
        $this->assertSame([], $result['performance']['trend']);
        $this->assertSame([], $result['subjects']);
        $this->assertSame([], $result['concepts']);
        $this->assertSame([], $result['strengths']);
        $this->assertSame([], $result['weaknesses']);
        $this->assertSame([], $result['insights']);
    }

    // --------------------------------------------------------
    // 2. One completed analysis
    // --------------------------------------------------------
    public function testOneCompletedAnalysisReturnsSingleExamData(): void
    {
        $studentId = 'student-1';
        $analysisId = 'analysis-1';
        $subject = 'Mathematics';
        $aiAnalysis = $this->buildDirectAiAnalysis(
            subject: $subject,
            score: 25,
            totalMarks: 30,
            percentage: 83.33,
            conceptAssessments: [
                $this->buildConceptAssessment('Algebra', 'STRONG', 0.9),
            ],
            strengths: ['Good algebra skills'],
            weaknesses: ['Sign errors'],
            recommendations: ['Review sign conventions'],
        );

        $analysis = $this->createAnalysisDocument($analysisId, $studentId, 'DONE', $subject, $aiAnalysis);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn([$analysis]);

        $service = $this->createService($mongoRepo);
        $result = $service->getIntelligence($studentId);

        $this->assertSame(1, $result['overview']['totalCompletedExams']);
        $this->assertSame(1, $result['overview']['totalSubjects']);
        $this->assertSame(25, $result['overview']['latestScore']);
        $this->assertSame(25, $result['overview']['bestScore']);
        $this->assertSame(25, $result['overview']['lowestScore']);
        $this->assertNull($result['overview']['improvementFromPrevious']);
        $this->assertSame(83.33, $result['overview']['overallAverage']);

        $this->assertCount(1, $result['performance']['trend']);
        $this->assertSame($analysisId, $result['performance']['trend'][0]['analysisId']);
        $this->assertSame(83.33, $result['performance']['trend'][0]['percentage']);

        $this->assertCount(1, $result['subjects']);
        $this->assertSame('Mathematics', $result['subjects'][0]['subject']);
        $this->assertSame(83.33, $result['subjects'][0]['average']);
    }

    // --------------------------------------------------------
    // 3. Multiple completed analyses - chronological ordering
    // --------------------------------------------------------
    public function testMultipleCompletedAnalysesAreOrderedChronologically(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $analysis1 = $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
            subject: 'Mathematics', score: 20, totalMarks: 30, percentage: 66.67,
        ), $now->modify('-2 days'));

        $analysis2 = $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Physics', $this->buildDirectAiAnalysis(
            subject: 'Physics', score: 24, totalMarks: 30, percentage: 80.0,
        ), $now->modify('-1 day'));

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn([$analysis1, $analysis2]);

        $service = $this->createService($mongoRepo);
        $result = $service->getIntelligence($studentId);

        $trend = $result['performance']['trend'];
        $this->assertSame('Mathematics', $trend[0]['subject']);
        $this->assertSame(66.67, $trend[0]['percentage']);
        $this->assertSame('Physics', $trend[1]['subject']);
        $this->assertSame(80.0, $trend[1]['percentage']);
    }

    // --------------------------------------------------------
    // 4. Multiple subjects
    // --------------------------------------------------------
    public function testMultipleSubjectsAreGroupedCorrectly(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 24, totalMarks: 30, percentage: 80.0,
            ), $now->modify('-2 days')),
            $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 27, totalMarks: 30, percentage: 90.0,
            ), $now->modify('-1 day')),
            $this->createAnalysisDocument('analysis-3', $studentId, 'DONE', 'Physics', $this->buildDirectAiAnalysis(
                subject: 'Physics', score: 18, totalMarks: 30, percentage: 60.0,
            ), $now),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getIntelligence($studentId);

        $this->assertSame(2, $result['overview']['totalSubjects']);

        $mathSubject = null;
        $physicsSubject = null;
        foreach ($result['subjects'] as $subject) {
            if ($subject['subject'] === 'Mathematics') {
                $mathSubject = $subject;
            } elseif ($subject['subject'] === 'Physics') {
                $physicsSubject = $subject;
            }
        }

        $this->assertNotNull($mathSubject);
        $this->assertSame(2, $mathSubject['exams']);
        $this->assertSame(85.0, $mathSubject['average']);
        $this->assertSame(90.0, $mathSubject['latest']);
        $this->assertSame(90.0, $mathSubject['highest']);
        $this->assertSame(80.0, $mathSubject['lowest']);
        $this->assertSame(10.0, $mathSubject['trend']);

        $this->assertNotNull($physicsSubject);
        $this->assertSame(1, $physicsSubject['exams']);
        $this->assertSame(60.0, $physicsSubject['average']);
    }

    // --------------------------------------------------------
    // 5. Concept improving
    // --------------------------------------------------------
    public function testConceptImprovingTrendIsDetected(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $conceptAssessments1 = [$this->buildConceptAssessment('Quadratic Equations', 'NEEDS_ATTENTION', 0.4)];
        $conceptAssessments2 = [$this->buildConceptAssessment('Quadratic Equations', 'DEVELOPING', 0.6)];
        $conceptAssessments3 = [$this->buildConceptAssessment('Quadratic Equations', 'STRONG', 0.9)];

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 20, totalMarks: 30, percentage: 66.67,
                conceptAssessments: $conceptAssessments1,
            ), $now->modify('-3 days')),
            $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 24, totalMarks: 30, percentage: 80.0,
                conceptAssessments: $conceptAssessments2,
            ), $now->modify('-2 days')),
            $this->createAnalysisDocument('analysis-3', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 27, totalMarks: 30, percentage: 90.0,
                conceptAssessments: $conceptAssessments3,
            ), $now->modify('-1 day')),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getIntelligence($studentId);

        $concepts = $result['concepts'];
        $quadratic = null;
        foreach ($concepts as $concept) {
            if ($concept['concept'] === 'Quadratic Equations') {
                $quadratic = $concept;
            }
        }

        $this->assertNotNull($quadratic);
        $this->assertSame('STRONG', $quadratic['latestStatus']);
        $this->assertSame('IMPROVING', $quadratic['trend']);
        $this->assertSame('Mathematics', $quadratic['subject']);
        $this->assertSame(3, $quadratic['attempts']);
        $this->assertCount(3, $quadratic['history']);
    }

    // --------------------------------------------------------
    // 6. Concept declining
    // --------------------------------------------------------
    public function testConceptDecliningTrendIsDetected(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $conceptAssessments1 = [$this->buildConceptAssessment('Trigonometry', 'STRONG', 0.9)];
        $conceptAssessments2 = [$this->buildConceptAssessment('Trigonometry', 'DEVELOPING', 0.6)];
        $conceptAssessments3 = [$this->buildConceptAssessment('Trigonometry', 'NEEDS_ATTENTION', 0.4)];

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 27, totalMarks: 30, percentage: 90.0,
                conceptAssessments: $conceptAssessments1,
            ), $now->modify('-3 days')),
            $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 24, totalMarks: 30, percentage: 80.0,
                conceptAssessments: $conceptAssessments2,
            ), $now->modify('-2 days')),
            $this->createAnalysisDocument('analysis-3', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 18, totalMarks: 30, percentage: 60.0,
                conceptAssessments: $conceptAssessments3,
            ), $now->modify('-1 day')),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getIntelligence($studentId);

        $concepts = $result['concepts'];
        $trig = null;
        foreach ($concepts as $concept) {
            if ($concept['concept'] === 'Trigonometry') {
                $trig = $concept;
            }
        }

        $this->assertNotNull($trig);
        $this->assertSame('NEEDS_ATTENTION', $trig['latestStatus']);
        $this->assertSame('DECLINING', $trig['trend']);
        $this->assertSame(3, $trig['attempts']);
    }

    // --------------------------------------------------------
    // 7. Concept stable
    // --------------------------------------------------------
    public function testConceptStableTrendIsDetected(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $conceptAssessments = [$this->buildConceptAssessment('Geometry', 'DEVELOPING', 0.5)];

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 22, totalMarks: 30, percentage: 73.33,
                conceptAssessments: $conceptAssessments,
            ), $now->modify('-2 days')),
            $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 22, totalMarks: 30, percentage: 73.33,
                conceptAssessments: $conceptAssessments,
            ), $now->modify('-1 day')),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getIntelligence($studentId);

        $concepts = $result['concepts'];
        $geo = null;
        foreach ($concepts as $concept) {
            if ($concept['concept'] === 'Geometry') {
                $geo = $concept;
            }
        }

        $this->assertNotNull($geo);
        $this->assertSame('DEVELOPING', $geo['latestStatus']);
        $this->assertSame('STABLE', $geo['trend']);
    }

    // --------------------------------------------------------
    // 8. Repeated weakness
    // --------------------------------------------------------
    public function testRepeatedWeaknessIsAggregated(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 20, totalMarks: 30, percentage: 66.67,
                weaknesses: ['Sign errors'],
            ), $now->modify('-2 days')),
            $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 22, totalMarks: 30, percentage: 73.33,
                weaknesses: ['Sign errors'],
            ), $now->modify('-1 day')),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getIntelligence($studentId);

        $signErrors = null;
        foreach ($result['weaknesses'] as $weakness) {
            if ($weakness['concept'] === 'Sign errors') {
                $signErrors = $weakness;
            }
        }

        $this->assertNotNull($signErrors);
        $this->assertSame(2, $signErrors['frequency']);
    }

    // --------------------------------------------------------
    // 9. Repeated strength
    // --------------------------------------------------------
    public function testRepeatedStrengthIsAggregated(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 24, totalMarks: 30, percentage: 80.0,
                strengths: ['Algebraic manipulation'],
            ), $now->modify('-2 days')),
            $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 27, totalMarks: 30, percentage: 90.0,
                strengths: ['Algebraic manipulation'],
            ), $now->modify('-1 day')),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getIntelligence($studentId);

        $algebra = null;
        foreach ($result['strengths'] as $strength) {
            if ($strength['strength'] === 'Algebraic manipulation') {
                $algebra = $strength;
            }
        }

        $this->assertNotNull($algebra);
        $this->assertSame(2, $algebra['occurrences']);
        $this->assertSame('Mathematics', $algebra['subject']);
    }

    // --------------------------------------------------------
    // 10. Incomplete analyses are ignored
    // --------------------------------------------------------
    public function testIncompleteAnalysesAreIgnored(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $aiAnalysis = $this->buildDirectAiAnalysis(
            subject: 'Mathematics', score: 25, totalMarks: 30, percentage: 83.33,
        );

        $analysisDone = $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $aiAnalysis, $now);
        $analysisDraft = $this->createAnalysisDocument('analysis-2', $studentId, 'DRAFT', 'Physics', null, $now);
        $analysisProcessing = $this->createAnalysisDocument('analysis-3', $studentId, 'PROCESSING', 'Physics', null, $now);
        $analysisFailed = $this->createAnalysisDocument('analysis-4', $studentId, 'FAILED', 'Chemistry', null, $now);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn([$analysisDone, $analysisDraft, $analysisProcessing, $analysisFailed]);

        $service = $this->createService($mongoRepo);
        $result = $service->getIntelligence($studentId);

        $this->assertSame(1, $result['overview']['totalCompletedExams']);
        $this->assertSame('Mathematics', $result['subjects'][0]['subject']);
    }

    // --------------------------------------------------------
    // 11. Failed analyses are ignored
    // --------------------------------------------------------
    public function testFailedAnalysesAreIgnored(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $aiAnalysis = $this->buildDirectAiAnalysis(
            subject: 'Mathematics', score: 25, totalMarks: 30, percentage: 83.33,
        );

        $analysisDone = $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $aiAnalysis, $now);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn([$analysisDone]);

        $service = $this->createService($mongoRepo);
        $result = $service->getIntelligence($studentId);

        $this->assertSame(1, $result['overview']['totalCompletedExams']);
        $this->assertSame(1, $result['overview']['totalSubjects']);
    }

    // --------------------------------------------------------
    // 12. Student isolation
    // --------------------------------------------------------
    public function testStudentIsolation(): void
    {
        $student1Id = 'student-1';
        $student2Id = 'student-2';
        $now = new \DateTimeImmutable();

        $aiAnalysis1 = $this->buildDirectAiAnalysis(
            subject: 'Mathematics', score: 25, totalMarks: 30, percentage: 83.33,
        );
        $aiAnalysis2 = $this->buildDirectAiAnalysis(
            subject: 'Physics', score: 15, totalMarks: 30, percentage: 50.0,
        );

        $analysis1 = $this->createAnalysisDocument('analysis-1', $student1Id, 'DONE', 'Mathematics', $aiAnalysis1, $now);
        $analysis2 = $this->createAnalysisDocument('analysis-2', $student2Id, 'DONE', 'Physics', $aiAnalysis2, $now);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturnCallback(function (string $sid) use ($student1Id, $analysis1, $analysis2) {
            if ($sid === $student1Id) {
                return [$analysis1];
            }
            return [$analysis2];
        });

        $service = $this->createService($mongoRepo);

        $result1 = $service->getIntelligence($student1Id);
        $this->assertSame(1, $result1['overview']['totalCompletedExams']);
        $this->assertSame('Mathematics', $result1['subjects'][0]['subject']);

        $result2 = $service->getIntelligence($student2Id);
        $this->assertSame(1, $result2['overview']['totalCompletedExams']);
        $this->assertSame('Physics', $result2['subjects'][0]['subject']);
    }

    // --------------------------------------------------------
    // 13. Correct chronological ordering
    // --------------------------------------------------------
    public function testPerformanceTrendIsChronologicallyOrdered(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $analyses = [
            $this->createAnalysisDocument('analysis-3', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 27, totalMarks: 30, percentage: 90.0,
            ), $now->modify('-1 day')),
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Physics', $this->buildDirectAiAnalysis(
                subject: 'Physics', score: 20, totalMarks: 30, percentage: 66.67,
            ), $now->modify('-3 days')),
            $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Chemistry', $this->buildDirectAiAnalysis(
                subject: 'Chemistry', score: 22, totalMarks: 30, percentage: 73.33,
            ), $now->modify('-2 days')),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getIntelligence($studentId);

        $trend = $result['performance']['trend'];
        $this->assertSame('Physics', $trend[0]['subject']);
        $this->assertSame(66.67, $trend[0]['percentage']);
        $this->assertSame('Chemistry', $trend[1]['subject']);
        $this->assertSame(73.33, $trend[1]['percentage']);
        $this->assertSame('Mathematics', $trend[2]['subject']);
        $this->assertSame(90.0, $trend[2]['percentage']);
    }

    // --------------------------------------------------------
    // 14. Improvement from previous
    // --------------------------------------------------------
    public function testImprovementFromPreviousIsCalculated(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 20, totalMarks: 30, percentage: 66.67,
            ), $now->modify('-2 days')),
            $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 27, totalMarks: 30, percentage: 90.0,
            ), $now->modify('-1 day')),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getIntelligence($studentId);

        $this->assertSame(23.33, $result['overview']['improvementFromPrevious']);
        $this->assertSame('IMPROVING', $result['trends']['overall']);
    }

    // --------------------------------------------------------
    // 15. Concept from questionAnalysis
    // --------------------------------------------------------
    public function testConceptsFromQuestionAnalysisAreAggregated(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $qa1 = [
            $this->buildQuestionAnalysis('1', 'CORRECT', 'Algebra', 'STRONG'),
            $this->buildQuestionAnalysis('2', 'INCORRECT', 'Geometry', 'NEEDS_ATTENTION'),
        ];
        $qa2 = [
            $this->buildQuestionAnalysis('1', 'CORRECT', 'Algebra', 'STRONG'),
            $this->buildQuestionAnalysis('2', 'CORRECT', 'Geometry', 'DEVELOPING'),
        ];

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 20, totalMarks: 30, percentage: 66.67,
                questionAnalysis: $qa1,
            ), $now->modify('-2 days')),
            $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 27, totalMarks: 30, percentage: 90.0,
                questionAnalysis: $qa2,
            ), $now->modify('-1 day')),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getIntelligence($studentId);

        $algebra = null;
        $geometry = null;
        foreach ($result['concepts'] as $concept) {
            if ($concept['concept'] === 'Algebra') {
                $algebra = $concept;
            } elseif ($concept['concept'] === 'Geometry') {
                $geometry = $concept;
            }
        }

        $this->assertNotNull($algebra);
        $this->assertSame('STRONG', $algebra['latestStatus']);
        $this->assertSame('STABLE', $algebra['trend']);

        $this->assertNotNull($geometry);
        $this->assertSame('DEVELOPING', $geometry['latestStatus']);
        $this->assertSame('IMPROVING', $geometry['trend']);
    }

    // --------------------------------------------------------
    // 16. Learning plan generated from data
    // --------------------------------------------------------
    public function testLearningPlanGeneratedFromStoredData(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 18, totalMarks: 30, percentage: 60.0,
                conceptAssessments: [$this->buildConceptAssessment('Calculus', 'NEEDS_ATTENTION', 0.3)],
                recommendations: ['Practice derivatives daily'],
            ), $now->modify('-1 day')),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getIntelligence($studentId);

        $this->assertNotEmpty($result['learningPlan']['priorities']);
        $this->assertNotEmpty($result['learningPlan']['recentRecommendations']);

        $calcPriority = null;
        foreach ($result['learningPlan']['priorities'] as $priority) {
            if ($priority['concept'] === 'Calculus') {
                $calcPriority = $priority;
            }
        }

        $this->assertNotNull($calcPriority);
        $this->assertSame('HIGH', $calcPriority['priority']);
        $this->assertSame('ANALYSIS_DATA', $calcPriority['source']);
    }

    // --------------------------------------------------------
    // 17. Doubt context populated
    // --------------------------------------------------------
    public function testDoubtContextIsPopulated(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 25, totalMarks: 30, percentage: 83.33,
                mistakes: [
                    ['questionNumber' => '1', 'subQuestion' => null, 'type' => 'CALCULATION', 'description' => 'Sign error', 'confidence' => 0.9],
                ],
            ), $now->modify('-1 day')),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getIntelligence($studentId);

        $this->assertNotNull($result['doubtContext']['recentExam']);
        $this->assertSame('Mathematics', $result['doubtContext']['recentExam']['subject']);
        $this->assertNotEmpty($result['doubtContext']['recentMistakes']);
        $this->assertSame('Sign error', $result['doubtContext']['recentMistakes'][0]['description']);
    }

    // --------------------------------------------------------
    // 18. Insights generated
    // --------------------------------------------------------
    public function testInsightsGeneratedWhenSufficientDataExists(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 20, totalMarks: 30, percentage: 66.67,
                strengths: ['Algebraic manipulation'],
            ), $now->modify('-2 days')),
            $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 27, totalMarks: 30, percentage: 90.0,
                strengths: ['Algebraic manipulation'],
            ), $now->modify('-1 day')),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getIntelligence($studentId);

        $this->assertNotEmpty($result['insights']);

        $types = array_column($result['insights'], 'type');
        $this->assertContains('IMPROVEMENT', $types);
        $this->assertContains('STRENGTH', $types);
    }

    // --------------------------------------------------------
    // 19. Legacy analysis format supported
    // --------------------------------------------------------
    public function testLegacyAnalysisFormatIsSupported(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $legacyAiAnalysis = [
            'version' => '1.0',
            'provider' => 'openai',
            'model' => 'gpt-4',
            'summary' => [
                'totalMarks' => 30,
                'marksAwarded' => 24,
                'percentage' => 80,
                'attempted' => 25,
                'correct' => 20,
                'partiallyCorrect' => 3,
                'incorrect' => 2,
                'unanswered' => 5,
            ],
            'questions' => [
                [
                    'questionNumber' => '1',
                    'resultStatus' => 'CORRECT',
                    'marksAwarded' => 1,
                    'maxMarks' => 1,
                    'topics' => ['Algebra'],
                    'concepts' => ['Linear Equations'],
                ],
            ],
            'recommendations' => ['Practice linear equations'],
            'completedAt' => $now->format(\DateTimeInterface::ATOM),
        ];

        $analysis = $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $legacyAiAnalysis, $now);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn([$analysis]);

        $service = $this->createService($mongoRepo);
        $result = $service->getIntelligence($studentId);

        $this->assertSame(1, $result['overview']['totalCompletedExams']);
        $this->assertSame(80.0, $result['overview']['overallAverage']);
        $this->assertSame(24, $result['overview']['latestScore']);
        $this->assertSame(20, $result['overview']['totalCorrect']);
        $this->assertSame(2, $result['overview']['totalIncorrect']);
        $this->assertSame(25, $result['overview']['totalAttempted']);
        $this->assertSame(['Practice linear equations'], $result['recommendations']);
    }

    // --------------------------------------------------------
    // 20. Best and lowest scores calculated
    // --------------------------------------------------------
    public function testBestAndLowestScoresCalculated(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 15, totalMarks: 30, percentage: 50.0,
            ), $now->modify('-3 days')),
            $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Physics', $this->buildDirectAiAnalysis(
                subject: 'Physics', score: 25, totalMarks: 30, percentage: 83.33,
            ), $now->modify('-2 days')),
            $this->createAnalysisDocument('analysis-3', $studentId, 'DONE', 'Chemistry', $this->buildDirectAiAnalysis(
                subject: 'Chemistry', score: 21, totalMarks: 30, percentage: 70.0,
            ), $now->modify('-1 day')),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getIntelligence($studentId);

        $this->assertSame(21, $result['overview']['latestScore']);
        $this->assertSame(25, $result['overview']['bestScore']);
        $this->assertSame(15, $result['overview']['lowestScore']);
    }
}
