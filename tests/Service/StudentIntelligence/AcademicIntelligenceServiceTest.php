<?php

declare(strict_types=1);

namespace App\Tests\Service\StudentIntelligence;

use App\DTO\StudentIntelligence\AcademicIntelligenceResponseDTO;
use App\Document\Analysis;
use App\Document\Documents;
use App\Document\Exam;
use App\Document\Processing;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use App\Service\StudentIntelligence\AcademicIntelligenceService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AcademicIntelligenceServiceTest extends TestCase
{
    private function createService(MongoAnalysisRepository $mongoRepo): AcademicIntelligenceService
    {
        return new AcademicIntelligenceService(
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
        if ($subject !== null) {
            $analysis->getExam()->setSubject($subject);
        }
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
            'evaluation' => [
                'evaluationStatus' => $evaluationStatus,
                'marksAwarded' => $evaluationStatus === 'CORRECT' ? 1 : 0,
                'maxMarks' => 1,
            ],
            'concept' => $conceptName !== '' ? [
                'name' => $conceptName,
                'status' => $conceptStatus ?: 'STRONG',
            ] : null,
        ];
    }

    private function buildMistake(string $description, string $type = 'CALCULATION', float $confidence = 0.9): array
    {
        return [
            'questionNumber' => '1',
            'subQuestion' => null,
            'type' => $type,
            'description' => $description,
            'confidence' => $confidence,
        ];
    }

    private function setCompletedAt(Analysis $analysis, \DateTimeImmutable $dt): void
    {
        $analysis->getProcessing()->setCompletedAt($dt);
    }

    // --------------------------------------------------------
    // 1. No completed analyses
    // --------------------------------------------------------
    public function testNoCompletedAnalysesReturnsEmptyDashboard(): void
    {
        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn([]);

        $service = $this->createService($mongoRepo);
        $result = $service->getDashboard('student-1');

        $this->assertInstanceOf(AcademicIntelligenceResponseDTO::class, $result);
        $this->assertSame('student-1', $result->studentId);
        $this->assertSame(0, $result->performance->totalAssessments);
        $this->assertSame(0, $result->performance->assessmentsWithValidMarks);
        $this->assertNull($result->performance->overallScore);
        $this->assertSame([], $result->performance->historicalTrend);
        $this->assertFalse($result->learningHealth->available);
        $this->assertNull($result->learningHealth->score);
        $this->assertSame([], $result->weakestTopics);
        $this->assertSame('SUGGESTED', $result->todaysGoal->type);
        $this->assertNull($result->todaysGoal->durationMinutes);
        $this->assertSame('INSUFFICIENT_DATA', $result->aiCoachInsight->type);
        $this->assertSame([], $result->subjectPerformance);
        $this->assertSame([], $result->upcomingTests);
        $this->assertNull($result->recentTest->analysisId);
        $this->assertFalse($result->studyStreak->available);
    }

    // --------------------------------------------------------
    // 2. One completed analysis - learning health unavailable
    // --------------------------------------------------------
    public function testOneCompletedAnalysisLearningHealthUnavailable(): void
    {
        $studentId = 'student-1';
        $analysisId = 'analysis-1';
        $now = new \DateTimeImmutable();

        $aiAnalysis = $this->buildDirectAiAnalysis(
            subject: 'Mathematics',
            score: 25,
            totalMarks: 30,
            percentage: 83.33,
        );
        $analysis = $this->createAnalysisDocument($analysisId, $studentId, 'DONE', 'Mathematics', $aiAnalysis, $now);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn([$analysis]);

        $service = $this->createService($mongoRepo);
        $result = $service->getDashboard($studentId);

        $this->assertSame(1, $result->performance->totalAssessments);
        $this->assertSame(1, $result->performance->assessmentsWithValidMarks);
        $this->assertSame(83.33, $result->performance->overallScore);
        $this->assertCount(1, $result->performance->historicalTrend);
        $this->assertSame('Mathematics', $result->performance->historicalTrend[0]->subject);
        $this->assertFalse($result->learningHealth->available);
        $this->assertNull($result->learningHealth->score);
        $this->assertStringContainsString('0.50 × PerformanceScore', $result->learningHealth->formula);
        $this->assertSame($analysisId, $result->recentTest->analysisId);
        $this->assertSame($analysisId, $result->recentTest->analysisId);
    }

    // --------------------------------------------------------
    // 3. Multiple analyses - weighted overall score (not blind average of percentages)
    // --------------------------------------------------------
    public function testOverallScoreUsesWeightedMarksNotAverageOfPercentages(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 10, totalMarks: 20, percentage: 50.0,
            ), $now->modify('-2 days')),
            $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Physics', $this->buildDirectAiAnalysis(
                subject: 'Physics', score: 45, totalMarks: 50, percentage: 90.0,
            ), $now->modify('-1 day')),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getDashboard($studentId);

        // Weighted: (10 + 45) / (20 + 50) * 100 = 55 / 70 * 100 = 78.57
        // Blind average of percentages: (50 + 90) / 2 = 70
        $this->assertSame(78.57, $result->performance->overallScore);
        $this->assertSame(2, $result->performance->assessmentsWithValidMarks);
    }

    // --------------------------------------------------------
    // 4. Chronological historical trend
    // --------------------------------------------------------
    public function testHistoricalTrendIsChronological(): void
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
        $result = $service->getDashboard($studentId);

        $trend = $result->performance->historicalTrend;
        $this->assertCount(3, $trend);
        $this->assertSame('Physics', $trend[0]->subject);
        $this->assertSame('analysis-1', $trend[0]->analysisId);
        $this->assertSame('Chemistry', $trend[1]->subject);
        $this->assertSame('Mathematics', $trend[2]->subject);
    }

    // --------------------------------------------------------
    // 5. Multiple subjects grouped
    // --------------------------------------------------------
    public function testSubjectsGroupedCorrectly(): void
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
        $result = $service->getDashboard($studentId);

        $math = null;
        $physics = null;
        foreach ($result->subjectPerformance as $subject) {
            if ($subject->subject === 'Mathematics') {
                $math = $subject;
            } elseif ($subject->subject === 'Physics') {
                $physics = $subject;
            }
        }

        $this->assertNotNull($math);
        $this->assertSame(2, $math->exams);
        $this->assertSame(85.0, $math->average);
        $this->assertSame(90.0, $math->latest);
        $this->assertSame(90.0, $math->highest);
        $this->assertSame(80.0, $math->lowest);
        $this->assertSame('IMPROVING', $math->trend);

        $this->assertNotNull($physics);
        $this->assertSame(1, $physics->exams);
        $this->assertSame(60.0, $physics->average);
    }

    // --------------------------------------------------------
    // 6. Missing fields handled gracefully
    // --------------------------------------------------------
    public function testMissingFieldsDoNotCrash(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $aiAnalysis = [
            'source' => 'OPENAI_DIRECT_ANALYSIS',
            'generatedAt' => $now->format(\DateTimeInterface::ATOM),
        ];
        $analysis = $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', null, $aiAnalysis, $now);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn([$analysis]);

        $service = $this->createService($mongoRepo);
        $result = $service->getDashboard($studentId);

        $this->assertSame(1, $result->performance->totalAssessments);
        $this->assertSame(0, $result->performance->assessmentsWithValidMarks);
        $this->assertNull($result->performance->overallScore);
        $this->assertFalse($result->learningHealth->available);
        $this->assertSame([], $result->weakestTopics);
    }

    // --------------------------------------------------------
    // 7. Unknown status strings handled gracefully
    // --------------------------------------------------------
    public function testUnknownStatusHandledGracefully(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $aiAnalysis = $this->buildDirectAiAnalysis(
            subject: 'Mathematics',
            score: 20,
            totalMarks: 30,
            percentage: 66.67,
            conceptAssessments: [
                $this->buildConceptAssessment('Algebra', 'UNKNOWN_FUTURE_STATUS', 0.5),
                $this->buildConceptAssessment('Geometry', 'STRONG', 0.9),
            ],
        );
        $aiAnalysis2 = $this->buildDirectAiAnalysis(
            subject: 'Mathematics',
            score: 24,
            totalMarks: 30,
            percentage: 80.0,
            conceptAssessments: [
                $this->buildConceptAssessment('Algebra', 'STRONG', 0.8),
            ],
        );
        $analysis1 = $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $aiAnalysis, $now->modify('-2 days'));
        $analysis2 = $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Mathematics', $aiAnalysis2, $now->modify('-1 day'));

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn([$analysis1, $analysis2]);

        $service = $this->createService($mongoRepo);
        $result = $service->getDashboard($studentId);

        $this->assertTrue($result->learningHealth->available);
        $this->assertSame(2, $result->subjectPerformance[0]->conceptSummary['strong']);
        $this->assertSame(0, $result->subjectPerformance[0]->conceptSummary['needsAttention']);
    }

    // --------------------------------------------------------
    // 8. Duplicate concept in multiple sections not double-counted per analysis
    // --------------------------------------------------------
    public function testConceptNotDoubleCountedWithinSameAnalysis(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $qa = [
            $this->buildQuestionAnalysis('1', 'INCORRECT', 'Algebra', 'NEEDS_ATTENTION'),
        ];
        $aiAnalysis = $this->buildDirectAiAnalysis(
            subject: 'Mathematics',
            score: 20,
            totalMarks: 30,
            percentage: 66.67,
            conceptAssessments: [
                $this->buildConceptAssessment('Algebra', 'NEEDS_ATTENTION', 0.7),
            ],
            questionAnalysis: $qa,
        );
        $analysis = $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $aiAnalysis, $now);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn([$analysis]);

        $service = $this->createService($mongoRepo);
        $result = $service->getDashboard($studentId);

        $algebra = null;
        foreach ($result->weakestTopics as $topic) {
            if ($topic->topic === 'Algebra') {
                $algebra = $topic;
            }
        }
        $this->assertNotNull($algebra);
        $this->assertSame(1, $algebra->evidenceCount);
    }

    // --------------------------------------------------------
    // 9. Recurring weakness detected across analyses
    // --------------------------------------------------------
    public function testRecurringWeaknessAggregatedAcrossAnalyses(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 20, totalMarks: 30, percentage: 66.67,
                conceptAssessments: [$this->buildConceptAssessment('Algebra', 'NEEDS_ATTENTION', 0.7)],
            ), $now->modify('-2 days')),
            $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 22, totalMarks: 30, percentage: 73.33,
                conceptAssessments: [$this->buildConceptAssessment('Algebra', 'NEEDS_ATTENTION', 0.6)],
            ), $now->modify('-1 day')),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getDashboard($studentId);

        $algebra = null;
        foreach ($result->weakestTopics as $topic) {
            if ($topic->topic === 'Algebra') {
                $algebra = $topic;
            }
        }
        $this->assertNotNull($algebra);
        $this->assertSame(2, $algebra->evidenceCount);
        $this->assertTrue($algebra->recurring);
        $this->assertCount(2, $algebra->supportingAnalyses);
    }

    // --------------------------------------------------------
    // 10. Today's goal - recurring topic gets duration
    // --------------------------------------------------------
    public function testTodaysGoalRecurringTopicHasDuration(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 20, totalMarks: 30, percentage: 66.67,
                conceptAssessments: [$this->buildConceptAssessment('Algebra', 'NEEDS_ATTENTION', 0.7)],
            ), $now->modify('-2 days')),
            $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 22, totalMarks: 30, percentage: 73.33,
                conceptAssessments: [$this->buildConceptAssessment('Algebra', 'NEEDS_ATTENTION', 0.6)],
            ), $now->modify('-1 day')),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getDashboard($studentId);

        $this->assertSame('SUGGESTED', $result->todaysGoal->type);
        $this->assertSame('Algebra', $result->todaysGoal->topic);
        $this->assertSame('Mathematics', $result->todaysGoal->subject);
        $this->assertNotNull($result->todaysGoal->durationMinutes);
        $this->assertSame(30, $result->todaysGoal->durationMinutes);
    }

    // --------------------------------------------------------
    // 11. Today's goal - single occurrence no duration
    // --------------------------------------------------------
    public function testTodaysGoalSingleOccurrenceNoDuration(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 20, totalMarks: 30, percentage: 66.67,
                conceptAssessments: [$this->buildConceptAssessment('Algebra', 'NEEDS_ATTENTION', 0.7)],
            ), $now->modify('-1 day')),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getDashboard($studentId);

        $this->assertSame('SUGGESTED', $result->todaysGoal->type);
        $this->assertSame('Algebra', $result->todaysGoal->topic);
        $this->assertNull($result->todaysGoal->durationMinutes);
        $this->assertStringContainsString('not yet established as recurring', $result->todaysGoal->reason);
    }

    // --------------------------------------------------------
    // 12. AI coach insight - improvement detected
    // --------------------------------------------------------
    public function testAICoachInsightImprovementDetected(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 20, totalMarks: 30, percentage: 60.0,
            ), $now->modify('-2 days')),
            $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 27, totalMarks: 30, percentage: 90.0,
            ), $now->modify('-1 day')),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getDashboard($studentId);

        $this->assertSame('IMPROVEMENT', $result->aiCoachInsight->type);
        $this->assertSame('Mathematics', $result->aiCoachInsight->subject);
        $this->assertNotNull($result->aiCoachInsight->confidence);
        $this->assertTrue($result->aiCoachInsight->confidence > 0);
        $this->assertSame('POSITIVE', $result->aiCoachInsight->severity);
        $this->assertNotEmpty($result->aiCoachInsight->evidence);
    }

    // --------------------------------------------------------
    // 13. Study streak from analysis dates
    // --------------------------------------------------------
    public function testStudyStreakCalculatedFromDates(): void
    {
        $studentId = 'student-1';
        $base = new \DateTimeImmutable('2026-10-10');

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 20, totalMarks: 30, percentage: 60.0,
            ), $base->modify('-2 days')),
            $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Physics', $this->buildDirectAiAnalysis(
                subject: 'Physics', score: 25, totalMarks: 30, percentage: 80.0,
            ), $base->modify('-1 day')),
            $this->createAnalysisDocument('analysis-3', $studentId, 'DONE', 'Chemistry', $this->buildDirectAiAnalysis(
                subject: 'Chemistry', score: 21, totalMarks: 30, percentage: 70.0,
            ), $base),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getDashboard($studentId);

        $this->assertTrue($result->studyStreak->available);
        $this->assertSame(3, $result->studyStreak->currentStreak);
        $this->assertSame('2026-10-10', $result->studyStreak->lastActivityDate);
    }

    // --------------------------------------------------------
    // 14. Study streak with gap
    // --------------------------------------------------------
    public function testStudyStreakResetsOnGap(): void
    {
        $studentId = 'student-1';
        $base = new \DateTimeImmutable('2026-10-10');

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 20, totalMarks: 30, percentage: 60.0,
            ), $base->modify('-3 days')),
            $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Physics', $this->buildDirectAiAnalysis(
                subject: 'Physics', score: 25, totalMarks: 30, percentage: 80.0,
            ), $base->modify('-1 day')),
            $this->createAnalysisDocument('analysis-3', $studentId, 'DONE', 'Chemistry', $this->buildDirectAiAnalysis(
                subject: 'Chemistry', score: 21, totalMarks: 30, percentage: 70.0,
            ), $base),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getDashboard($studentId);

        $this->assertSame(2, $result->studyStreak->currentStreak);
    }

    // --------------------------------------------------------
    // 15. Student isolation
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

        $result1 = $service->getDashboard($student1Id);
        $this->assertSame(1, $result1->performance->totalAssessments);
        $this->assertSame('Mathematics', $result1->subjectPerformance[0]->subject);

        $result2 = $service->getDashboard($student2Id);
        $this->assertSame(1, $result2->performance->totalAssessments);
        $this->assertSame('Physics', $result2->subjectPerformance[0]->subject);
    }

    // --------------------------------------------------------
    // 16. Learning health with sufficient data
    // --------------------------------------------------------
    public function testLearningHealthCalculatedWhenSufficientData(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $analyses = [
            $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 20, totalMarks: 30, percentage: 60.0,
                conceptAssessments: [$this->buildConceptAssessment('Algebra', 'STRONG', 0.9)],
            ), $now->modify('-2 days')),
            $this->createAnalysisDocument('analysis-2', $studentId, 'DONE', 'Mathematics', $this->buildDirectAiAnalysis(
                subject: 'Mathematics', score: 27, totalMarks: 30, percentage: 90.0,
                conceptAssessments: [$this->buildConceptAssessment('Algebra', 'STRONG', 0.9)],
            ), $now->modify('-1 day')),
        ];

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn($analyses);

        $service = $this->createService($mongoRepo);
        $result = $service->getDashboard($studentId);

        $this->assertTrue($result->learningHealth->available);
        $this->assertNotNull($result->learningHealth->score);
        $this->assertTrue($result->learningHealth->score > 0);
        $this->assertNotNull($result->learningHealth->label);
        $this->assertArrayHasKey('performanceScore', $result->learningHealth->components);
        $this->assertArrayHasKey('conceptHealth', $result->learningHealth->components);
        $this->assertArrayHasKey('consistencyScore', $result->learningHealth->components);
        $this->assertSame(0.50, $result->learningHealth->components['performanceWeight']);
        $this->assertSame(0.30, $result->learningHealth->components['conceptWeight']);
        $this->assertSame(0.20, $result->learningHealth->components['consistencyWeight']);
    }

    // --------------------------------------------------------
    // 17. Non-DONE analyses excluded
    // --------------------------------------------------------
    public function testNonDoneAnalysesExcluded(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $aiAnalysis = $this->buildDirectAiAnalysis(
            subject: 'Mathematics', score: 25, totalMarks: 30, percentage: 83.33,
        );

        $done = $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $aiAnalysis, $now);
        $draft = $this->createAnalysisDocument('analysis-2', $studentId, 'DRAFT', 'Physics', null, $now);
        $processing = $this->createAnalysisDocument('analysis-3', $studentId, 'PROCESSING', 'Physics', null, $now);
        $failed = $this->createAnalysisDocument('analysis-4', $studentId, 'FAILED', 'Chemistry', null, $now);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn([$done, $draft, $processing, $failed]);

        $service = $this->createService($mongoRepo);
        $result = $service->getDashboard($studentId);

        $this->assertSame(1, $result->performance->totalAssessments);
        $this->assertSame('Mathematics', $result->subjectPerformance[0]->subject);
    }

    // --------------------------------------------------------
    // 18. Upcoming tests empty (no analysis-backed source)
    // --------------------------------------------------------
    public function testUpcomingTestsEmpty(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $aiAnalysis = $this->buildDirectAiAnalysis(
            subject: 'Mathematics', score: 25, totalMarks: 30, percentage: 83.33,
        );
        $analysis = $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $aiAnalysis, $now);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn([$analysis]);

        $service = $this->createService($mongoRepo);
        $result = $service->getDashboard($studentId);

        $this->assertSame([], $result->upcomingTests);
    }

    // --------------------------------------------------------
    // 19. Recent test populated
    // --------------------------------------------------------
    public function testRecentTestPopulated(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $aiAnalysis = $this->buildDirectAiAnalysis(
            subject: 'Mathematics', score: 25, totalMarks: 30, percentage: 83.33,
        );
        $analysis = $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $aiAnalysis, $now);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn([$analysis]);

        $service = $this->createService($mongoRepo);
        $result = $service->getDashboard($studentId);

        $this->assertSame('analysis-1', $result->recentTest->analysisId);
        $this->assertSame('Mathematics', $result->recentTest->subject);
        $this->assertSame(25, $result->recentTest->score);
        $this->assertSame(30, $result->recentTest->totalMarks);
        $this->assertSame(83.33, $result->recentTest->percentage);
    }

    // --------------------------------------------------------
    // 20. Null marks not treated as zero
    // --------------------------------------------------------
    public function testNullMarksNotTreatedAsZero(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $aiAnalysis = [
            'currentExamAnalysis' => [
                'performance' => [
                    'score' => null,
                    'totalMarks' => null,
                    'percentage' => null,
                ],
                'conceptAssessments' => [],
                'weaknesses' => [],
                'needsAttention' => [],
                'conceptGaps' => [],
                'recommendations' => [],
                'mistakes' => [],
                'strengths' => [],
            ],
            'source' => 'OPENAI_DIRECT_ANALYSIS',
            'generatedAt' => $now->format(\DateTimeInterface::ATOM),
            'questionAnalysis' => [],
        ];
        $analysis = $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $aiAnalysis, $now);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn([$analysis]);

        $service = $this->createService($mongoRepo);
        $result = $service->getDashboard($studentId);

        $this->assertSame(1, $result->performance->totalAssessments);
        $this->assertSame(0, $result->performance->assessmentsWithValidMarks);
        $this->assertNull($result->performance->overallScore);
        $this->assertNull($result->performance->historicalTrend[0]->score);
        $this->assertNull($result->performance->historicalTrend[0]->totalMarks);
    }

    // --------------------------------------------------------
    // 21. Percentage out of range rejected
    // --------------------------------------------------------
    public function testPercentageOutOfRangeRejected(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $aiAnalysis = [
            'currentExamAnalysis' => [
                'performance' => [
                    'score' => 25,
                    'totalMarks' => 30,
                    'percentage' => 150.0,
                ],
                'conceptAssessments' => [],
                'weaknesses' => [],
                'needsAttention' => [],
                'conceptGaps' => [],
                'recommendations' => [],
                'mistakes' => [],
                'strengths' => [],
            ],
            'source' => 'OPENAI_DIRECT_ANALYSIS',
            'generatedAt' => $now->format(\DateTimeInterface::ATOM),
            'questionAnalysis' => [],
        ];
        $analysis = $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $aiAnalysis, $now);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn([$analysis]);

        $service = $this->createService($mongoRepo);
        $result = $service->getDashboard($studentId);

        $this->assertNull($result->performance->historicalTrend[0]->percentage);
    }

    // --------------------------------------------------------
    // 22. Non-numeric score rejected
    // --------------------------------------------------------
    public function testNonNumericScoreRejected(): void
    {
        $studentId = 'student-1';
        $now = new \DateTimeImmutable();

        $aiAnalysis = [
            'currentExamAnalysis' => [
                'performance' => [
                    'score' => 'not_a_number',
                    'totalMarks' => 30,
                    'percentage' => 83.33,
                ],
                'conceptAssessments' => [],
                'weaknesses' => [],
                'needsAttention' => [],
                'conceptGaps' => [],
                'recommendations' => [],
                'mistakes' => [],
                'strengths' => [],
            ],
            'source' => 'OPENAI_DIRECT_ANALYSIS',
            'generatedAt' => $now->format(\DateTimeInterface::ATOM),
            'questionAnalysis' => [],
        ];
        $analysis = $this->createAnalysisDocument('analysis-1', $studentId, 'DONE', 'Mathematics', $aiAnalysis, $now);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findCompletedByStudentId')->willReturn([$analysis]);

        $service = $this->createService($mongoRepo);
        $result = $service->getDashboard($studentId);

        $this->assertNull($result->performance->historicalTrend[0]->score);
        $this->assertSame(0, $result->performance->assessmentsWithValidMarks);
    }
}
