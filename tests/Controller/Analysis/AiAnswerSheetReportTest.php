<?php

declare(strict_types=1);

namespace App\Tests\Controller\Analysis;

use App\Document\Analysis;
use App\Document\Documents;
use App\Document\Exam;
use App\Document\Processing;
use App\Entity\Analysis as PgAnalysis;
use App\Entity\ExamSet;
use App\Entity\Institute;
use App\Entity\User;
use App\Enum\AnalysisStatus;
use App\Enum\InstituteStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\AnalysisRepository;
use App\Repository\ExamSetRepository;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class AiAnswerSheetReportTest extends WebTestCase
{
    private ?KernelBrowser $client = null;
    private ?Uuid $student1Id = null;
    private ?Uuid $student2Id = null;
    private ?string $student1Token = null;
    private ?string $student2Token = null;
    private array $createdEmails = [];
    private ?string $examSetId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $unique = uniqid();

        $student1 = new User();
        $this->student1Id = Uuid::v7();
        $reflection = new \ReflectionClass($student1);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($student1, $this->student1Id);
        $student1->setEmail('student1-report-' . $unique . '@edupulse.ai');
        $student1->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student1->setRoles([UserRole::Student->value]);
        $student1->setStatus(UserStatus::Active);
        $student1->verify();
        $student1->setFirstName('Aparajita');
        $student1->setLastName('Mehotra');

        $student2 = new User();
        $this->student2Id = Uuid::v7();
        $reflection2 = new \ReflectionClass($student2);
        $property2 = $reflection2->getProperty('id');
        $property2->setAccessible(true);
        $property2->setValue($student2, $this->student2Id);
        $student2->setEmail('student2-report-' . $unique . '@edupulse.ai');
        $student2->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student2->setRoles([UserRole::Student->value]);
        $student2->setStatus(UserStatus::Active);
        $student2->verify();
        $student2->setFirstName('Student');
        $student2->setLastName('Two');

        $institute = new Institute();
        $instituteId = Uuid::v7();
        $instituteReflection = new \ReflectionClass($institute);
        $instituteProperty = $instituteReflection->getProperty('id');
        $instituteProperty->setAccessible(true);
        $instituteProperty->setValue($institute, $instituteId);
        $institute->setName('Test Institute Report');
        $institute->setCode('TIR' . $unique);
        $institute->setEmail('test-institute-report-' . $unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);

        $examSet = new ExamSet();
        $examSet->setStudent($student1);
        $examSet->setInstitute($institute);
        $examSet->setAcademicYear('2026');
        $examSet->setAcademicClass('10');
        $examSet->setSection('A');
        $examSet->setExamName('Mid Term');
        $examSet->setExamSubType('English');

        $entityManager->persist($institute);
        $entityManager->persist($student1);
        $entityManager->persist($student2);
        $entityManager->persist($examSet);
        $entityManager->flush();

        $this->examSetId = $examSet->getId()->toRfc4122();
        $this->createdEmails = [$student1->getEmail(), $student2->getEmail()];

        $this->student1Token = $this->login($student1->getEmail(), 'Student123!');
        $this->student2Token = $this->login($student2->getEmail(), 'Student123!');

        $mongoAnalysisRepo = $container->get(MongoAnalysisRepository::class);
        foreach ($mongoAnalysisRepo->findByStudentId((string) $this->student1Id) as $analysis) {
            $mongoAnalysisRepo->remove($analysis);
        }
        foreach ($mongoAnalysisRepo->findByStudentId((string) $this->student2Id) as $analysis) {
            $mongoAnalysisRepo->remove($analysis);
        }
    }

    public function testStudentCanDownloadOwnCompletedReport(): void
    {
        $analysisId = $this->createCompletedAnalysis((string) $this->student1Id, 'Aparajita Mehotra', 'Roll101');

        $this->client->request('GET', '/api/v1/student/analyses/' . $analysisId . '/ai-report', [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('EduPulse_AI_Answer_Sheet_', $response->headers->get('Content-Disposition') ?? '');
        $this->assertStringContainsString('.pdf', $response->headers->get('Content-Disposition') ?? '');
        $this->assertNotEmpty($response->getContent());
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function testStudentCannotDownloadAnotherStudentsReport(): void
    {
        $analysisId = $this->createCompletedAnalysis((string) $this->student1Id, 'Aparajita Mehotra', 'Roll101');

        $this->client->request('GET', '/api/v1/student/analyses/' . $analysisId . '/ai-report', [], [], $this->authHeaders($this->student2Token));
        $response = $this->client->getResponse();

        $this->assertSame(404, $response->getStatusCode(), $response->getContent());
    }

    public function testMissingAnalysisReturns404(): void
    {
        $this->client->request('GET', '/api/v1/student/analyses/nonexistent-id/ai-report', [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();

        $this->assertSame(404, $response->getStatusCode(), $response->getContent());
    }

    public function testIncompleteAnalysisCannotGenerateReport(): void
    {
        $analysisId = $this->createIncompleteAnalysis((string) $this->student1Id);

        $this->client->request('GET', '/api/v1/student/analyses/' . $analysisId . '/ai-report', [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();

        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
    }

    public function testMissingAiAnalysisCannotGenerateReport(): void
    {
        $analysisId = $this->createAnalysisWithoutAi((string) $this->student1Id);

        $this->client->request('GET', '/api/v1/student/analyses/' . $analysisId . '/ai-report', [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();

        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
    }

    public function testReportFilenameContainsStudentAndSubject(): void
    {
        $analysisId = $this->createCompletedAnalysis((string) $this->student1Id, 'Aparajita Mehotra', 'Roll101');

        $this->client->request('GET', '/api/v1/student/analyses/' . $analysisId . '/ai-report', [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();

        $this->assertSame(200, $response->getStatusCode());
        $contentDisposition = $response->headers->get('Content-Disposition') ?? '';
        $this->assertStringContainsString('EduPulse_AI_Answer_Sheet_', $contentDisposition);
        $this->assertStringContainsString('.pdf', $contentDisposition);
        $this->assertStringContainsString('Extracted_Student', $contentDisposition);
        $this->assertStringContainsString('Physics', $contentDisposition);
    }

    public function testReportPdfIsValidAndNonEmpty(): void
    {
        $analysisId = $this->createCompletedAnalysis((string) $this->student1Id, 'Aparajita Mehotra', 'Roll101');

        $this->client->request('GET', '/api/v1/student/analyses/' . $analysisId . '/ai-report', [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();

        $this->assertSame(200, $response->getStatusCode());
        $content = $response->getContent();
        $this->assertNotEmpty($content);
        $this->assertStringStartsWith('%PDF', $content);
        $this->assertGreaterThan(1000, strlen($content));
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

    private function createCompletedAnalysis(string $studentId, string $studentName, string $rollNumber): string
    {
        $container = static::getContainer();
        $entityManager = $container->get('doctrine')->getManager();
        $mongoAnalysisRepo = $container->get(MongoAnalysisRepository::class);

        $analysisId = Uuid::v7()->toRfc4122();

        $pgAnalysis = new PgAnalysis();
        $pgAnalysis->setStudent($entityManager->getReference(User::class, Uuid::fromString($studentId)));
        $pgAnalysis->setAnalysisId($analysisId);
        $pgAnalysis->setSource('EXTERNAL_UPLOAD');
        $pgAnalysis->setStatus(AnalysisStatus::Done->value);
        $pgAnalysis->setExamSet($entityManager->getReference(ExamSet::class, Uuid::fromString($this->examSetId)));
        $pgAnalysis->setStudentName($studentName);
        $pgAnalysis->setRollNumber($rollNumber);
        $pgAnalysis->setTotalMarks(10);
        $pgAnalysis->setScoredMarks(7);

        $mongoAnalysis = new Analysis();
        $mongoAnalysis->setAnalysisId($analysisId);
        $mongoAnalysis->setStudentId($studentId);
        $mongoAnalysis->setSource('EXTERNAL_UPLOAD');
        $mongoAnalysis->setStatus('DONE');
        $mongoAnalysis->setDocuments(new Documents());
        $mongoAnalysis->setExam(new Exam());
        $mongoAnalysis->getExam()->setTitle('Mid Term');
        $mongoAnalysis->getExam()->setSubject('English');
        $mongoAnalysis->getExam()->setTotalMarks(10);
        $mongoAnalysis->getExam()->setExtractedMetadata([
            'studentName' => ['value' => 'Extracted Student', 'status' => 'EXTRACTED', 'confidence' => 0.95],
            'rollNumber' => ['value' => '99', 'status' => 'EXTRACTED', 'confidence' => 0.9],
            'class' => ['value' => '9', 'status' => 'EXTRACTED', 'confidence' => 0.92],
            'section' => ['value' => 'B', 'status' => 'EXTRACTED', 'confidence' => 0.88],
            'subject' => ['value' => 'Physics', 'status' => 'EXTRACTED', 'confidence' => 0.96],
            'examTitle' => ['value' => 'Final Examination', 'status' => 'EXTRACTED', 'confidence' => 0.94],
            'totalMarks' => ['value' => '50', 'status' => 'EXTRACTED', 'confidence' => 0.97],
            'examDate' => ['value' => '2026-11-15', 'status' => 'EXTRACTED', 'confidence' => 0.91],
        ]);
        $mongoAnalysis->setProcessing(new Processing());
        $mongoAnalysis->getProcessing()->setStatus('DONE');
        $mongoAnalysis->getProcessing()->setCompletedAt(new \DateTimeImmutable());

        $mongoAnalysis->setAiAnalysis([
            'currentExamAnalysis' => [
                'performance' => [
                    'score' => 7,
                    'totalMarks' => 10,
                    'percentage' => 70.0,
                ],
                'strengths' => ['Good algebra skills'],
                'weaknesses' => ['Sign errors'],
                'needsAttention' => ['Time management'],
                'conceptGaps' => ['Quadratic formula'],
                'recommendations' => ['Review sign conventions', 'Practice quadratic equations'],
                'conceptAssessments' => [
                    [
                        'concept' => 'Algebra',
                        'status' => 'STRONG',
                        'confidence' => 0.9,
                        'evidence' => [],
                    ],
                    [
                        'concept' => 'Geometry',
                        'status' => 'NEEDS_ATTENTION',
                        'confidence' => 0.4,
                        'evidence' => [],
                    ],
                ],
                'mistakes' => [],
                'evidence' => [],
            ],
            'source' => 'OPENAI_DIRECT_ANALYSIS',
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'questionAnalysis' => [
                [
                    'questionNumber' => '1',
                    'subQuestion' => null,
                    'status' => 'evaluated',
                    'question' => 'Sample question 1',
                    'studentAnswer' => 'Sample answer 1',
                    'evaluation' => [
                        'evaluationStatus' => 'CORRECT',
                        'correctness' => 'correct',
                        'marksAwarded' => 5,
                        'maxMarks' => 5,
                        'whatStudentDidWell' => 'Clear explanation',
                        'mistake' => '',
                        'whyItIsWrong' => '',
                        'missingPoints' => [],
                    ],
                    'concept' => [
                        'name' => 'Algebra',
                        'status' => 'STRONG',
                        'explanation' => 'Student shows strong algebraic reasoning.',
                    ],
                    'improvement' => [
                        'whatToLearn' => '',
                        'whatToPractice' => '',
                        'recommendedAction' => '',
                    ],
                    'evidence' => [],
                ],
                [
                    'questionNumber' => '2',
                    'subQuestion' => null,
                    'status' => 'evaluated',
                    'question' => 'Sample question 2',
                    'studentAnswer' => 'Sample answer 2',
                    'evaluation' => [
                        'evaluationStatus' => 'INCORRECT',
                        'correctness' => 'incorrect',
                        'marksAwarded' => 2,
                        'maxMarks' => 5,
                        'whatStudentDidWell' => '',
                        'mistake' => 'Sign error in calculation',
                        'whyItIsWrong' => 'The sign was flipped during step 2.',
                        'missingPoints' => ['Missing negative sign', 'Wrong final value'],
                    ],
                    'concept' => [
                        'name' => 'Geometry',
                        'status' => 'NEEDS_ATTENTION',
                        'explanation' => 'Student struggles with geometric proofs.',
                    ],
                    'improvement' => [
                        'whatToLearn' => 'Review geometric theorems',
                        'whatToPractice' => 'Practice more geometry problems',
                        'recommendedAction' => 'Revisit chapter 5',
                    ],
                    'evidence' => [],
                ],
            ],
        ]);

        $entityManager->persist($pgAnalysis);
        $entityManager->flush();

        $dm = $mongoAnalysisRepo->getDocumentManager();
        $dm->persist($mongoAnalysis);
        $dm->flush();

        return $analysisId;
    }

    private function createIncompleteAnalysis(string $studentId): string
    {
        $container = static::getContainer();
        $entityManager = $container->get('doctrine')->getManager();
        $mongoAnalysisRepo = $container->get(MongoAnalysisRepository::class);

        $analysisId = Uuid::v7()->toRfc4122();

        $pgAnalysis = new PgAnalysis();
        $pgAnalysis->setStudent($entityManager->getReference(User::class, Uuid::fromString($studentId)));
        $pgAnalysis->setAnalysisId($analysisId);
        $pgAnalysis->setSource('EXTERNAL_UPLOAD');
        $pgAnalysis->setStatus(AnalysisStatus::Processing->value);
        $pgAnalysis->setExamSet($entityManager->getReference(ExamSet::class, Uuid::fromString($this->examSetId)));

        $mongoAnalysis = new Analysis();
        $mongoAnalysis->setAnalysisId($analysisId);
        $mongoAnalysis->setStudentId($studentId);
        $mongoAnalysis->setSource('EXTERNAL_UPLOAD');
        $mongoAnalysis->setStatus('PROCESSING');
        $mongoAnalysis->setDocuments(new Documents());
        $mongoAnalysis->setExam(new Exam());
        $mongoAnalysis->setProcessing(new Processing());
        $mongoAnalysis->getProcessing()->setStatus('PROCESSING');

        $entityManager->persist($pgAnalysis);
        $entityManager->flush();

        $dm = $mongoAnalysisRepo->getDocumentManager();
        $dm->persist($mongoAnalysis);
        $dm->flush();

        return $analysisId;
    }

    private function createAnalysisWithoutAi(string $studentId): string
    {
        $container = static::getContainer();
        $entityManager = $container->get('doctrine')->getManager();
        $mongoAnalysisRepo = $container->get(MongoAnalysisRepository::class);

        $analysisId = Uuid::v7()->toRfc4122();

        $pgAnalysis = new PgAnalysis();
        $pgAnalysis->setStudent($entityManager->getReference(User::class, Uuid::fromString($studentId)));
        $pgAnalysis->setAnalysisId($analysisId);
        $pgAnalysis->setSource('EXTERNAL_UPLOAD');
        $pgAnalysis->setStatus(AnalysisStatus::Done->value);
        $pgAnalysis->setExamSet($entityManager->getReference(ExamSet::class, Uuid::fromString($this->examSetId)));

        $mongoAnalysis = new Analysis();
        $mongoAnalysis->setAnalysisId($analysisId);
        $mongoAnalysis->setStudentId($studentId);
        $mongoAnalysis->setSource('EXTERNAL_UPLOAD');
        $mongoAnalysis->setStatus('DONE');
        $mongoAnalysis->setDocuments(new Documents());
        $mongoAnalysis->setExam(new Exam());
        $mongoAnalysis->setProcessing(new Processing());
        $mongoAnalysis->getProcessing()->setStatus('DONE');
        $mongoAnalysis->getProcessing()->setCompletedAt(new \DateTimeImmutable());
        $mongoAnalysis->setAiAnalysis(null);

        $entityManager->persist($pgAnalysis);
        $entityManager->flush();

        $dm = $mongoAnalysisRepo->getDocumentManager();
        $dm->persist($mongoAnalysis);
        $dm->flush();

        return $analysisId;
    }

    protected function tearDown(): void
    {
        if ($this->client === null) {
            parent::tearDown();
            return;
        }

        try {
            $container = $this->client->getContainer();
            $entityManager = $container->get('doctrine')->getManager();
            $userRepo = $entityManager->getRepository(User::class);

            foreach ($userRepo->findBy(['email' => $this->createdEmails]) as $user) {
                $entityManager->remove($user);
            }
            $entityManager->flush();

            $mongoAnalysisRepo = $container->get(MongoAnalysisRepository::class);
            foreach ($mongoAnalysisRepo->findByStudentId((string) $this->student1Id) as $analysis) {
                $mongoAnalysisRepo->remove($analysis);
            }
            foreach ($mongoAnalysisRepo->findByStudentId((string) $this->student2Id) as $analysis) {
                $mongoAnalysisRepo->remove($analysis);
            }
        } catch (\Throwable) {
        }

        $this->client = null;
        $this->student1Token = null;
        $this->student2Token = null;
        $this->createdEmails = [];

        parent::tearDown();
    }
}
