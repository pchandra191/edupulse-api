<?php

declare(strict_types=1);

namespace App\Tests\Service\StudyPlan;

use App\Document\Analysis;
use App\Document\Documents;
use App\Document\Exam;
use App\Document\Processing;
use App\Entity\StudyTask;
use App\Entity\User;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use App\Repository\StudyTaskRepository;
use App\Service\StudyPlan\StudyPlanService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class StudyPlanServiceTest extends TestCase
{
    private Uuid $studentId;
    private User $student;
    private MongoAnalysisRepository $mongoRepo;
    private EntityManagerInterface $em;
    private StudyTaskRepository $taskRepo;
    /** @var StudyTask[] */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->studentId = Uuid::v7();

        $this->student = new User();
        $reflection = new \ReflectionClass($this->student);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($this->student, $this->studentId);

        $this->mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $this->taskRepo = $this->createMock(StudyTaskRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->persisted = [];
        $this->em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof StudyTask) {
                if ($entity->getStudentId() === null) {
                    $entity->setStudentId($this->studentId);
                }
                $now = new \DateTimeImmutable();
                $createdProp = (new \ReflectionClass(StudyTask::class))->getProperty('createdAt');
                $createdProp->setAccessible(true);
                $createdProp->setValue($entity, $now);
                $updatedProp = (new \ReflectionClass(StudyTask::class))->getProperty('updatedAt');
                $updatedProp->setAccessible(true);
                $updatedProp->setValue($entity, $now);
                $this->persisted[] = $entity;
            }
        });
        $this->em->method('flush')->willReturnCallback(function (): void {
        });
    }

    private function createService(): StudyPlanService
    {
        return new StudyPlanService(
            $this->em,
            $this->taskRepo,
            $this->mongoRepo,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function makeAnalysis(string $studentId, string $subject, array $aiAnalysis, ?\DateTimeImmutable $completedAt = null): Analysis
    {
        $analysis = new Analysis();
        $analysis->setAnalysisId('test-' . uniqid());
        $analysis->setStudentId($studentId);
        $analysis->setSource('OPENAI_DIRECT_ANALYSIS');
        $analysis->setStatus('DONE');
        $analysis->setDocuments(new Documents());
        $analysis->setExam(new Exam());
        $analysis->getExam()->setSubject($subject);
        $analysis->setProcessing(new Processing());
        $analysis->getProcessing()->setStatus('DONE');
        $analysis->getProcessing()->setCompletedAt($completedAt ?? new \DateTimeImmutable());
        $analysis->setAiAnalysis($aiAnalysis);

        return $analysis;
    }

    public function testPlanIsEmptyWhenNoCompletedAnalyses(): void
    {
        $this->mongoRepo->method('findCompletedByStudentId')->willReturn([]);
        $this->taskRepo->method('findByStudentId')->willReturn([]);

        $plan = $this->createService()->getPlan($this->student);

        $this->assertFalse($plan['hasCompletedAnalyses']);
        $this->assertSame(0, $plan['progress']['total']);
        $this->assertSame(0.0, $plan['progress']['rate']);
        $this->assertSame([], $plan['tasks']);
    }

    public function testNeedsAttentionConceptProducesHighPriorityTask(): void
    {
        $analysis = $this->makeAnalysis(
            (string) $this->studentId,
            'Mathematics',
            [
                'currentExamAnalysis' => [
                    'performance' => ['score' => 18, 'totalMarks' => 30, 'percentage' => 60.0],
                    'conceptAssessments' => [
                        ['concept' => 'Geometry', 'status' => 'NEEDS_ATTENTION', 'confidence' => 0.4],
                    ],
                ],
                'source' => 'OPENAI_DIRECT_ANALYSIS',
            ]
        );

        $this->mongoRepo->method('findCompletedByStudentId')->willReturn([$analysis]);
        $this->taskRepo->method('existsByKeyAndStudentId')->willReturn(false);
        $this->taskRepo->method('findByStudentId')->willReturnCallback(fn () => $this->persisted);

        $plan = $this->createService()->getPlan($this->student);

        $this->assertTrue($plan['hasCompletedAnalyses']);
        $this->assertSame(1, count($plan['tasks']));
        $task = $plan['tasks'][0];
        $this->assertSame('Review: Geometry', $task['title']);
        $this->assertSame('high', $task['priority']);
        $this->assertTrue($task['isAiRecommended']);
        $this->assertSame('Geometry', $task['topic']);
        $this->assertSame('Mathematics', $task['subject']);
    }

    public function testRecurringWeaknessEscalatesPriorityToHigh(): void
    {
        $ai = [
            'currentExamAnalysis' => [
                'conceptAssessments' => [
                    ['concept' => 'Algebra', 'status' => 'DEVELOPING', 'confidence' => 0.6],
                ],
            ],
            'source' => 'OPENAI_DIRECT_ANALYSIS',
        ];

        $now = new \DateTimeImmutable();
        $analysis1 = $this->makeAnalysis((string) $this->studentId, 'Mathematics', $ai, $now->modify('-2 days'));
        $analysis2 = $this->makeAnalysis((string) $this->studentId, 'Mathematics', $ai, $now->modify('-1 day'));
        $analysis2->setAnalysisId('test-' . uniqid());

        $this->mongoRepo->method('findCompletedByStudentId')->willReturn([$analysis1, $analysis2]);
        $this->taskRepo->method('existsByKeyAndStudentId')->willReturn(false);
        $this->taskRepo->method('findByStudentId')->willReturnCallback(fn () => $this->persisted);

        $plan = $this->createService()->getPlan($this->student);

        $this->assertSame(1, count($plan['tasks']));
        $task = $plan['tasks'][0];
        $this->assertSame('high', $task['priority']);
        $this->assertStringContainsString('recurred', $task['description']);
        $this->assertStringContainsString('2 completed assessments', $task['description']);
    }

    public function testExistingTaskIsNotReCreatedAndPreservesCompletion(): void
    {
        $analysis = $this->makeAnalysis(
            (string) $this->studentId,
            'Mathematics',
            [
                'currentExamAnalysis' => [
                    'conceptAssessments' => [
                        ['concept' => 'Geometry', 'status' => 'NEEDS_ATTENTION', 'confidence' => 0.4],
                    ],
                ],
                'source' => 'OPENAI_DIRECT_ANALYSIS',
            ]
        );

        $existing = new StudyTask();
        $existing->setStudentId($this->studentId);
        $existing->setTitle('Review: Geometry');
        $existing->setSubject('Mathematics');
        $existing->setTopic('Geometry');
        $existing->setPriority('high');
        $existing->setIsAiRecommended(true);

        $this->mongoRepo->method('findCompletedByStudentId')->willReturn([$analysis]);
        $this->taskRepo->method('existsByKeyAndStudentId')->willReturn(true);
        $this->taskRepo->method('findByStudentId')->willReturn([$existing]);

        $plan = $this->createService()->getPlan($this->student);

        $this->assertSame(1, count($plan['tasks']));
        $this->assertSame(0, count($this->persisted), 'Existing task should not be re-persisted.');
    }

    public function testDeletedTaskIsNotRecreated(): void
    {
        $analysis = $this->makeAnalysis(
            (string) $this->studentId,
            'Mathematics',
            [
                'currentExamAnalysis' => [
                    'weaknesses' => ['Sign errors'],
                ],
                'source' => 'OPENAI_DIRECT_ANALYSIS',
            ]
        );

        $this->mongoRepo->method('findCompletedByStudentId')->willReturn([$analysis]);
        $this->taskRepo->method('existsByKeyAndStudentId')->willReturn(true);
        $this->taskRepo->method('findByStudentId')->willReturn([]);

        $plan = $this->createService()->getPlan($this->student);

        $this->assertSame([], $plan['tasks']);
        $this->assertSame(0, count($this->persisted));
    }

    public function testLegacyAnalysisFormatIsSupported(): void
    {
        $legacy = [
            'version' => '1.0',
            'summary' => ['percentage' => 70],
            'recommendations' => ['Practice linear equations'],
            'source' => 'OPENAI_DIRECT_ANALYSIS',
        ];

        $analysis = $this->makeAnalysis((string) $this->studentId, 'Mathematics', $legacy);

        $this->mongoRepo->method('findCompletedByStudentId')->willReturn([$analysis]);
        $this->taskRepo->method('existsByKeyAndStudentId')->willReturn(false);
        $this->taskRepo->method('findByStudentId')->willReturnCallback(fn () => $this->persisted);

        $plan = $this->createService()->getPlan($this->student);

        $this->assertTrue($plan['hasCompletedAnalyses']);
        $titles = array_column($plan['tasks'], 'title');
        $this->assertContains('Practice linear equations', $titles);
    }

    public function testProgressMathCalculatesRate(): void
    {
        $this->mongoRepo->method('findCompletedByStudentId')->willReturn([]);
        $this->taskRepo->method('findByStudentId')->willReturn([
            $this->buildPersistedTask(true),
            $this->buildPersistedTask(false),
            $this->buildPersistedTask(true),
        ]);

        $plan = $this->createService()->getPlan($this->student);

        $this->assertSame(3, $plan['progress']['total']);
        $this->assertSame(2, $plan['progress']['completed']);
        $this->assertSame(66.67, $plan['progress']['rate']);
    }

    private function buildPersistedTask(bool $completed): StudyTask
    {
        $task = new StudyTask();
        $task->setStudentId($this->studentId);
        $task->setTitle('Task');
        $task->setPriority('medium');
        $task->setIsCompleted($completed);
        $task->setIsAiRecommended(false);

        return $task;
    }
}
