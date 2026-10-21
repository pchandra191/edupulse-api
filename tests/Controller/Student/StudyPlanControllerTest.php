<?php

declare(strict_types=1);

namespace App\Tests\Controller\Student;

use App\Document\Analysis;
use App\Document\Documents;
use App\Document\Exam;
use App\Document\Processing;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use App\Repository\StudyTaskRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class StudyPlanControllerTest extends WebTestCase
{
    private ?KernelBrowser $client = null;
    private ?Uuid $student1Id = null;
    private ?Uuid $student2Id = null;
    private ?string $student1Token = null;
    private ?string $student2Token = null;
    private array $createdEmails = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $unique = uniqid();

        $student1 = new User();
        $this->student1Id = Uuid::v7();
        $this->setUserId($student1, $this->student1Id);
        $student1Email = 'studyplan-student1-' . $unique . '@edupulse.ai';
        $student1->setEmail($student1Email);
        $student1->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student1->setRoles([UserRole::Student->value]);
        $student1->setStatus(UserStatus::Active);
        $student1->verify();
        $student1->setFirstName('Plan');
        $student1->setLastName('One');

        $student2 = new User();
        $this->student2Id = Uuid::v7();
        $this->setUserId($student2, $this->student2Id);
        $student2Email = 'studyplan-student2-' . $unique . '@edupulse.ai';
        $student2->setEmail($student2Email);
        $student2->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student2->setRoles([UserRole::Student->value]);
        $student2->setStatus(UserStatus::Active);
        $student2->verify();
        $student2->setFirstName('Plan');
        $student2->setLastName('Two');

        $entityManager->persist($student1);
        $entityManager->persist($student2);
        $entityManager->flush();

        $this->createdEmails = [$student1Email, $student2Email];

        $this->student1Token = $this->login($student1Email, 'Student123!');
        $this->student2Token = $this->login($student2Email, 'Student123!');

        $this->clearMongoAnalyses();
        $this->clearStudyTasks();
    }

    public function testPlanIsEmptyWhenNoCompletedAnalyses(): void
    {
        $this->client->request('GET', '/api/v1/student/study-plan', [], [], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertFalse($data['data']['hasCompletedAnalyses']);
        $this->assertSame(0, $data['data']['progress']['total']);
        $this->assertSame(0, $data['data']['progress']['completed']);
        $this->assertSame(0, $data['data']['progress']['rate']);
        $this->assertSame([], $data['data']['tasks']);
    }

    public function testPlanDerivesTasksFromCompletedAnalysis(): void
    {
        $this->createCompletedMongoAnalysis(
            (string) $this->student1Id,
            'Mathematics',
            [
                'currentExamAnalysis' => [
                    'performance' => ['score' => 18, 'totalMarks' => 30, 'percentage' => 60.0],
                    'strengths' => [],
                    'weaknesses' => ['Sign errors'],
                    'needsAttention' => [],
                    'conceptGaps' => [],
                    'recommendations' => [
                        ['action' => 'Practice quadratic equations', 'reason' => 'Quadratic equations were not attempted confidently.', 'priority' => 'high'],
                    ],
                    'conceptAssessments' => [
                        ['concept' => 'Geometry', 'status' => 'NEEDS_ATTENTION', 'confidence' => 0.4, 'evidence' => []],
                    ],
                    'mistakes' => [
                        ['description' => 'Flipped inequality sign when solving', 'confidence' => 0.9],
                    ],
                    'evidence' => [],
                ],
                'source' => 'OPENAI_DIRECT_ANALYSIS',
                'questionAnalysis' => [
                    [
                        'questionNumber' => '1',
                        'concept' => ['name' => 'Algebra', 'status' => 'NEEDS_ATTENTION', 'explanation' => ''],
                    ],
                ],
            ]
        );

        $this->client->request('GET', '/api/v1/student/study-plan', [], [], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertTrue($data['data']['hasCompletedAnalyses']);

        $tasks = $data['data']['tasks'];
        $this->assertNotEmpty($tasks);

        $byTitle = [];
        foreach ($tasks as $task) {
            $byTitle[$task['title']] = $task;
        }

        // Weakness string -> "Review: Sign errors"
        $this->assertArrayHasKey('Review: Sign errors', $byTitle);
        $this->assertTrue($byTitle['Review: Sign errors']['isAiRecommended']);
        $this->assertSame('medium', $byTitle['Review: Sign errors']['priority']);
        $this->assertSame('Sign errors', $byTitle['Review: Sign errors']['topic']);
        $this->assertSame('Mathematics', $byTitle['Review: Sign errors']['subject']);

        // Concept assessment NEEDS_ATTENTION -> high priority
        $this->assertArrayHasKey('Review: Geometry', $byTitle);
        $this->assertSame('high', $byTitle['Review: Geometry']['priority']);
        $this->assertTrue($byTitle['Review: Geometry']['isAiRecommended']);

        // AI recommendation array -> action used as title
        $this->assertArrayHasKey('Practice quadratic equations', $byTitle);
        $this->assertSame('high', $byTitle['Practice quadratic equations']['priority']);
        $this->assertSame('Mathematics', $byTitle['Practice quadratic equations']['subject']);

        // Question concept NEEDS_ATTENTION -> "Review: Algebra"
        $this->assertArrayHasKey('Review: Algebra', $byTitle);
        $this->assertTrue($byTitle['Review: Algebra']['isAiRecommended']);
    }

    public function testAiTasksAreNotDuplicatedOnRepeatedFetch(): void
    {
        $this->createCompletedMongoAnalysis(
            (string) $this->student1Id,
            'Mathematics',
            [
                'currentExamAnalysis' => [
                    'performance' => ['score' => 18, 'totalMarks' => 30, 'percentage' => 60.0],
                    'weaknesses' => ['Sign errors'],
                    'conceptAssessments' => [],
                ],
                'source' => 'OPENAI_DIRECT_ANALYSIS',
            ]
        );

        $this->client->request('GET', '/api/v1/student/study-plan', [], [], $this->authHeaders($this->student1Token));
        $firstCount = count(json_decode($this->client->getResponse()->getContent(), true)['data']['tasks']);

        $this->client->request('GET', '/api/v1/student/study-plan', [], [], $this->authHeaders($this->student1Token));
        $secondCount = count(json_decode($this->client->getResponse()->getContent(), true)['data']['tasks']);

        $this->assertSame($firstCount, $secondCount);
    }

    public function testManualTaskCanBeCreatedAndToggledCompleted(): void
    {
        $payload = json_encode([
            'title' => 'My custom study task',
            'subject' => 'Mathematics',
            'topic' => 'Algebra',
            'priority' => 'high',
            'estimatedMinutes' => 30,
        ]);

        $this->client->request('POST', '/api/v1/student/study-plan/tasks', [], [], $this->authHeaders($this->student1Token), $payload);
        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $created = $data['data'];
        $this->assertFalse($created['isAiRecommended']);
        $this->assertSame('My custom study task', $created['title']);

        $taskId = $created['id'];

        // Toggle completed
        $this->client->request('PUT', '/api/v1/student/study-plan/tasks/' . $taskId, [], [], $this->authHeaders($this->student1Token), json_encode(['isCompleted' => true]));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertTrue(json_decode($response->getContent(), true)['data']['isCompleted']);

        // Progress should reflect the completion
        $this->client->request('GET', '/api/v1/student/study-plan', [], [], $this->authHeaders($this->student1Token));
        $plan = json_decode($this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame(1, $plan['progress']['completed']);
        $this->assertSame(100, $plan['progress']['rate']);

        // Delete
        $this->client->request('DELETE', '/api/v1/student/study-plan/tasks/' . $taskId, [], [], $this->authHeaders($this->student1Token));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/student/study-plan', [], [], $this->authHeaders($this->student1Token));
        $plan = json_decode($this->client->getResponse()->getContent(), true)['data'];
        $this->assertSame(0, $plan['progress']['total']);
    }

    public function testCreateTaskValidationFailsWithoutTitle(): void
    {
        $payload = json_encode(['priority' => 'high']);

        $this->client->request('POST', '/api/v1/student/study-plan/tasks', [], [], $this->authHeaders($this->student1Token), $payload);
        $response = $this->client->getResponse();

        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testStudentCannotAccessAnotherStudentTasks(): void
    {
        $this->createCompletedMongoAnalysis(
            (string) $this->student1Id,
            'Mathematics',
            [
                'currentExamAnalysis' => ['performance' => [], 'weaknesses' => ['Sign errors']],
                'source' => 'OPENAI_DIRECT_ANALYSIS',
            ]
        );

        $this->client->request('GET', '/api/v1/student/study-plan', [], [], $this->authHeaders($this->student2Token));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['data']['hasCompletedAnalyses']);
        $this->assertSame([], $data['data']['tasks']);
    }

    public function testUnauthenticatedAccessIsRejected(): void
    {
        $this->client->request('GET', '/api/v1/student/study-plan');
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    private function createCompletedMongoAnalysis(string $studentId, string $subject, array $aiAnalysis): void
    {
        $container = static::getContainer();
        $mongoRepo = $container->get(MongoAnalysisRepository::class);

        $analysis = new Analysis();
        $analysis->setAnalysisId('studyplan-' . uniqid());
        $analysis->setStudentId($studentId);
        $analysis->setSource('OPENAI_DIRECT_ANALYSIS');
        $analysis->setStatus('DONE');
        $analysis->setDocuments(new Documents());
        $analysis->setExam(new Exam());
        $analysis->getExam()->setSubject($subject);
        $analysis->setProcessing(new Processing());
        $analysis->getProcessing()->setStatus('DONE');
        $analysis->getProcessing()->setCompletedAt(new \DateTimeImmutable());
        $analysis->setAiAnalysis($aiAnalysis);

        $dm = $mongoRepo->getDocumentManager();
        $dm->persist($analysis);
        $dm->flush();
    }

    private function clearMongoAnalyses(): void
    {
        $container = static::getContainer();
        $mongoRepo = $container->get(MongoAnalysisRepository::class);
        foreach ($mongoRepo->findByStudentId((string) ($this->student1Id ?? '00000000-0000-0000-0000-000000000000')) as $analysis) {
            $mongoRepo->remove($analysis);
        }
        foreach ($mongoRepo->findByStudentId((string) ($this->student2Id ?? '00000000-0000-0000-0000-000000000000')) as $analysis) {
            $mongoRepo->remove($analysis);
        }
    }

    private function clearStudyTasks(): void
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $repo = $entityManager->getRepository(\App\Entity\StudyTask::class);
        foreach ($repo->findBy(['studentId' => [$this->student1Id, $this->student2Id]]) as $task) {
            $entityManager->remove($task);
        }
        $entityManager->flush();
    }

    private function setUserId(User $user, Uuid $id): void
    {
        $reflection = new \ReflectionClass($user);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($user, $id);
    }

    private function login(string $email, string $password): string
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $email, 'password' => $password]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('accessToken', $data['data'] ?? []);

        return $data['data']['accessToken'];
    }

    private function authHeaders(string $token): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];
    }

    protected function tearDown(): void
    {
        if ($this->client === null) {
            parent::tearDown();
            return;
        }

        try {
            $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
            $userRepo = $entityManager->getRepository(User::class);
            foreach ($userRepo->findBy(['email' => $this->createdEmails]) as $user) {
                $entityManager->remove($user);
            }
            $entityManager->flush();
        } catch (\Throwable) {
        }

        $this->client = null;
        $this->student1Token = null;
        $this->student2Token = null;
        $this->createdEmails = [];

        parent::tearDown();
    }
}
