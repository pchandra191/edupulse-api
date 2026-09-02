<?php
declare(strict_types=1);

namespace App\Tests\Controller\ExamSessionModule;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\ExamSession;
use App\Entity\ExamSubject;
use App\Entity\Examination;
use App\Entity\Institute;
use App\Entity\QuestionPaper;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\ExamSessionStatus;
use App\Enum\InstituteStatus;
use App\Enum\QuestionPaperStatus;
use App\Enum\SectionStatus;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class ExamSessionControllerTest extends WebTestCase
{
    private ?string $superAdminToken = null;
    private ?string $instituteToken = null;
    private ?string $teacherToken = null;
    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
    private ?Uuid $testClassId = null;
    private ?Uuid $testSubjectId = null;
    private ?Uuid $testSectionId = null;
    private ?Uuid $testQuestionPaperId = null;
    private ?Uuid $testExaminationId = null;
    private ?Uuid $testExamSubjectId = null;
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private array $createdEmails = [];
    private string $unique = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $this->unique = uniqid();
        $this->testInstituteId = Uuid::v7();
        $this->testAcademicYearId = Uuid::v7();
        $this->testClassId = Uuid::v7();
        $this->testSubjectId = Uuid::v7();
        $this->testSectionId = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-exam-session-test-' . $this->unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for Exam Sessions');
        $institute->setCode('TESTES' . $this->unique);
        $institute->setEmail('test-institute-exam-session-' . $this->unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);

        $academicYear = new AcademicYear();
        $ayReflection = new \ReflectionClass($academicYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($academicYear, $this->testAcademicYearId);
        $academicYear->setInstitute($institute);
        $academicYear->setName('Test Academic Year ' . $this->unique);
        $academicYear->setCode('AY' . $this->unique);
        $academicYear->setStartDate(new \DateTimeImmutable('2025-01-01'));
        $academicYear->setEndDate(new \DateTimeImmutable('2025-12-31'));
        $academicYear->setStatus(AcademicYearStatus::Active);
        $academicYear->setIsActive(true);

        $academicClass = new AcademicClass();
        $classReflection = new \ReflectionClass($academicClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($academicClass, $this->testClassId);
        $academicClass->setInstitute($institute);
        $academicClass->setAcademicYear($academicYear);
        $academicClass->setName('Test Class ' . $this->unique);
        $academicClass->setCode('CLS' . $this->unique);
        $academicClass->setStatus(ClassStatus::Active);

        $section = new Section();
        $sectionReflection = new \ReflectionClass($section);
        $sectionProperty = $sectionReflection->getProperty('id');
        $sectionProperty->setAccessible(true);
        $sectionProperty->setValue($section, $this->testSectionId);
        $section->setAcademicClass($academicClass);
        $section->setName('Test Section ' . $this->unique);
        $section->setCode('SEC' . $this->unique);
        $section->setStatus(SectionStatus::Active);

        $subject = new Subject();
        $subjectReflection = new \ReflectionClass($subject);
        $subjectProperty = $subjectReflection->getProperty('id');
        $subjectProperty->setAccessible(true);
        $subjectProperty->setValue($subject, $this->testSubjectId);
        $subject->setInstitute($institute);
        $subject->setName('Test Subject ' . $this->unique);
        $subject->setCode('SUB' . $this->unique);
        $subject->setStatus(SubjectStatus::Active);

        $questionPaper = new QuestionPaper();
        $this->testQuestionPaperId = Uuid::v7();
        $qpReflection = new \ReflectionClass($questionPaper);
        $qpProperty = $qpReflection->getProperty('id');
        $qpProperty->setAccessible(true);
        $qpProperty->setValue($questionPaper, $this->testQuestionPaperId);
        $questionPaper->setInstitute($institute);
        $questionPaper->setAcademicYear($academicYear);
        $questionPaper->setSubject($subject);
        $questionPaper->setTitle('Test Question Paper ' . $this->unique);
        $questionPaper->setDate(new \DateTimeImmutable('2025-05-01'));
        $questionPaper->setDuration(60);
        $questionPaper->setMaximumMarks(100);
        $questionPaper->setStatus(QuestionPaperStatus::Finalized);

        $examination = new Examination();
        $this->testExaminationId = Uuid::v7();
        $examReflection = new \ReflectionClass($examination);
        $examProperty = $examReflection->getProperty('id');
        $examProperty->setAccessible(true);
        $examProperty->setValue($examination, $this->testExaminationId);
        $examination->setInstitute($institute);
        $examination->setAcademicYear($academicYear);
        $examination->setAcademicClass($academicClass);
        $examination->setName('Test Examination ' . $this->unique);
        $examination->setCode('EXAM' . $this->unique);
        $examination->setStartDate(new \DateTimeImmutable('2025-06-01'));
        $examination->setEndDate(new \DateTimeImmutable('2025-06-15'));
        $examination->setStatus(\App\Enum\ExaminationStatus::Draft);

        $examSubject = new ExamSubject();
        $this->testExamSubjectId = Uuid::v7();
        $esReflection = new \ReflectionClass($examSubject);
        $esProperty = $esReflection->getProperty('id');
        $esProperty->setAccessible(true);
        $esProperty->setValue($examSubject, $this->testExamSubjectId);
        $examSubject->setExamination($examination);
        $examSubject->setSubject($subject);
        $examSubject->setExamDate(new \DateTimeImmutable('2025-06-10'));
        $examSubject->setStartTime(new \DateTimeImmutable('09:00'));
        $examSubject->setEndTime(new \DateTimeImmutable('12:00'));
        $examSubject->setMaxMarks(100);
        $examSubject->setPassingMarks(40);
        $examSubject->setStatus(\App\Enum\ExaminationStatus::Draft);

        $instituteUser = new User();
        $instituteUser->setEmail('institute-exam-session-test-' . $this->unique . '@edupulse.ai');
        $instituteUser->setPassword(password_hash('InstituteUser123!', PASSWORD_BCRYPT));
        $instituteUser->setRoles([UserRole::Institute->value]);
        $instituteUser->setStatus(UserStatus::Active);
        $instituteUser->verify();
        $instituteUser->setInstitute($institute);

        $teacherUser = new User();
        $teacherUser->setEmail('teacher-exam-session-test-' . $this->unique . '@edupulse.ai');
        $teacherUser->setPassword(password_hash('TeacherUser123!', PASSWORD_BCRYPT));
        $teacherUser->setRoles([UserRole::Teacher->value]);
        $teacherUser->setStatus(UserStatus::Active);
        $teacherUser->verify();
        $teacherUser->setInstitute($institute);

        $entityManager->persist($superAdmin);
        $entityManager->persist($institute);
        $entityManager->persist($academicYear);
        $entityManager->persist($academicClass);
        $entityManager->persist($section);
        $entityManager->persist($subject);
        $entityManager->persist($questionPaper);
        $entityManager->persist($examination);
        $entityManager->persist($examSubject);
        $entityManager->persist($instituteUser);
        $entityManager->persist($teacherUser);
        $entityManager->flush();

        $this->createdEmails = [
            'superadmin-exam-session-test-' . $this->unique . '@edupulse.ai',
            'institute-exam-session-test-' . $this->unique . '@edupulse.ai',
            'teacher-exam-session-test-' . $this->unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login($this->client, 'superadmin-exam-session-test-' . $this->unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->instituteToken = $this->login($this->client, 'institute-exam-session-test-' . $this->unique . '@edupulse.ai', 'InstituteUser123!');
        $this->teacherToken = $this->login($this->client, 'teacher-exam-session-test-' . $this->unique . '@edupulse.ai', 'TeacherUser123!');
    }

    protected function tearDown(): void
    {
        if (empty($this->createdEmails)) {
            parent::tearDown();
            return;
        }

        try {
            $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

            $userRepo = $entityManager->getRepository(User::class);
            $instituteRepo = $entityManager->getRepository(Institute::class);

            $users = $userRepo->findBy(['email' => $this->createdEmails]);
            foreach ($users as $user) {
                $entityManager->remove($user);
            }

            if ($this->testInstituteId) {
                $institute = $instituteRepo->find($this->testInstituteId);
                if ($institute) {
                    $entityManager->remove($institute);
                }
            }

            $entityManager->flush();
        } catch (\Throwable $e) {
            // Ignore cleanup errors
        }

        $this->superAdminToken = null;
        $this->instituteToken = null;
        $this->testInstituteId = null;
        $this->testAcademicYearId = null;
        $this->testClassId = null;
        $this->testSubjectId = null;
        $this->testSectionId = null;
        $this->testQuestionPaperId = null;
        $this->testExaminationId = null;
        $this->testExamSubjectId = null;
        $this->createdEmails = [];
        $this->unique = '';

        parent::tearDown();
    }

    private function login($client, string $email, string $password): ?string
    {
        $client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $email, 'password' => $password]));

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        return $data['success'] ?? false ? $data['data']['accessToken'] ?? null : null;
    }

    private function getAuthHeaders(string $token): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];
    }

    private function createTestExamSession(string $token, array $overrides = []): string
    {
        $client = $this->client;

        $payload = array_merge([
            'questionPaperId' => (string) $this->testQuestionPaperId,
            'title' => 'Test Exam Session ' . $this->unique,
            'availableFrom' => '01-06-2027 09:00',
            'availableTo' => '01-06-2027 12:00',
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'sectionIds' => [(string) $this->testSectionId],
            'durationOverride' => 180,
            'shuffleQuestions' => true,
            'showResultsMode' => 'IMMEDIATE',
            'accessMode' => 'OPEN',
            'allowReview' => true,
            'totalMarks' => 100,
            'status' => 'DRAFT',
            'instituteId' => (string) $this->testInstituteId,
        ], $overrides);

        $client->request('POST', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($token), json_encode($payload));
        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        return $data['data']['id'];
    }

    public function testSuperAdminCanCreateExamSession(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
            'title' => 'Super Admin Exam Session ' . $this->unique,
            'availableFrom' => '01-06-2027 09:00',
            'availableTo' => '01-06-2027 12:00',
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'sectionIds' => [(string) $this->testSectionId],
            'durationOverride' => 180,
            'shuffleQuestions' => true,
            'showResultsMode' => 'IMMEDIATE',
            'accessMode' => 'OPEN',
            'allowReview' => true,
            'totalMarks' => 100,
            'status' => 'DRAFT',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertSame('Super Admin Exam Session ' . $this->unique, $data['data']['title']);
        $this->assertSame('DRAFT', $data['data']['status']);
    }

    public function testOnlyFinalizedQuestionPaperCanBeUsed(): void
    {
        $client = $this->client;

        $draftPaper = new QuestionPaper();
        $draftPaperId = Uuid::v7();
        $reflection = new \ReflectionClass($draftPaper);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($draftPaper, $draftPaperId);
        $draftPaper->setInstitute($client->getContainer()->get('doctrine')->getManager()->getRepository(Institute::class)->find($this->testInstituteId));
        $draftPaper->setAcademicYear($client->getContainer()->get('doctrine')->getManager()->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $draftPaper->setSubject($client->getContainer()->get('doctrine')->getManager()->getRepository(Subject::class)->find($this->testSubjectId));
        $draftPaper->setTitle('Draft Paper ' . $this->unique);
        $draftPaper->setDate(new \DateTimeImmutable('2025-05-01'));
        $draftPaper->setDuration(60);
        $draftPaper->setMaximumMarks(100);
        $draftPaper->setStatus(QuestionPaperStatus::Draft);

        $client->getContainer()->get('doctrine')->getManager()->persist($draftPaper);
        $client->getContainer()->get('doctrine')->getManager()->flush();

        $payload = json_encode([
            'questionPaperId' => (string) $draftPaperId,
            'title' => 'Test Draft Session ' . $this->unique,
            'availableFrom' => '01-06-2027 09:00',
            'availableTo' => '01-06-2027 12:00',
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'sectionIds' => [(string) $this->testSectionId],
            'totalMarks' => 100,
            'status' => 'DRAFT',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('finalized', strtolower($data['message']));
    }

    public function testValidWorkflowTransitionSchedule(): void
    {
        $client = $this->client;

        $sessionId = $this->createTestExamSession($this->superAdminToken);

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/schedule', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('SCHEDULED', $data['data']['status']);
    }

    public function testValidWorkflowTransitionPublish(): void
    {
        $client = $this->client;

        $sessionId = $this->createTestExamSession($this->superAdminToken);

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/schedule', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/publish', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('PUBLISHED', $data['data']['status']);
        $this->assertNotNull($data['data']['publishedAt']);
    }

    public function testInvalidWorkflowTransition(): void
    {
        $client = $this->client;

        $sessionId = $this->createTestExamSession($this->superAdminToken);

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/close', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Cannot transition', $data['message']);
    }

    public function testCancellation(): void
    {
        $client = $this->client;

        $sessionId = $this->createTestExamSession($this->superAdminToken);

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/cancel', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('CANCELLED', $data['data']['status']);
    }

    public function testClosing(): void
    {
        $client = $this->client;

        $sessionId = $this->createTestExamSession($this->superAdminToken);

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/schedule', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/publish', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/start', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/close', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('CLOSED', $data['data']['status']);
    }

    public function testArchivedOrCancelledSessionCannotBeModified(): void
    {
        $client = $this->client;

        $sessionId = $this->createTestExamSession($this->superAdminToken);

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/cancel', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $payload = json_encode([
            'title' => 'Updated Title',
        ]);

        $client->request('PUT', '/api/v1/exam-sessions/' . $sessionId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Only draft or scheduled exam sessions can be modified', $data['message']);
    }

    public function testDraftSessionCanBeUpdated(): void
    {
        $client = $this->client;

        $sessionId = $this->createTestExamSession($this->superAdminToken);

        $payload = json_encode([
            'title' => 'Updated Draft Title',
            'durationOverride' => 240,
        ]);

        $client->request('PUT', '/api/v1/exam-sessions/' . $sessionId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Updated Draft Title', $data['data']['title']);
        $this->assertSame(240, $data['data']['durationOverride']);
    }

    public function testScheduledSessionCannotBeUpdated(): void
    {
        $client = $this->client;

        $sessionId = $this->createTestExamSession($this->superAdminToken);

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/schedule', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $payload = json_encode([
            'title' => 'Updated Scheduled Title',
        ]);

        $client->request('PUT', '/api/v1/exam-sessions/' . $sessionId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Only draft or scheduled exam sessions can be modified', $data['message']);
    }

    public function testPublishedSessionCannotBeUpdated(): void
    {
        $client = $this->client;

        $sessionId = $this->createTestExamSession($this->superAdminToken);

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/schedule', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/publish', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $payload = json_encode([
            'title' => 'Updated Published Title',
        ]);

        $client->request('PUT', '/api/v1/exam-sessions/' . $sessionId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Only draft or scheduled exam sessions can be modified', $data['message']);
    }

    public function testActiveSessionCannotBeUpdated(): void
    {
        $client = $this->client;

        $sessionId = $this->createTestExamSession($this->superAdminToken);

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/schedule', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/publish', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/start', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $payload = json_encode([
            'title' => 'Updated Active Title',
        ]);

        $client->request('PUT', '/api/v1/exam-sessions/' . $sessionId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Only draft or scheduled exam sessions can be modified', $data['message']);
    }

    public function testClosedSessionCannotBeUpdated(): void
    {
        $client = $this->client;

        $sessionId = $this->createTestExamSession($this->superAdminToken);

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/schedule', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/publish', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/start', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/close', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $payload = json_encode([
            'title' => 'Updated Closed Title',
        ]);

        $client->request('PUT', '/api/v1/exam-sessions/' . $sessionId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Only draft or scheduled exam sessions can be modified', $data['message']);
    }

    public function testArchivedSessionCannotBeUpdated(): void
    {
        $client = $this->client;

        $sessionId = $this->createTestExamSession($this->superAdminToken);

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/schedule', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/publish', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/archive', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $payload = json_encode([
            'title' => 'Updated Archived Title',
        ]);

        $client->request('PUT', '/api/v1/exam-sessions/' . $sessionId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Only draft or scheduled exam sessions can be modified', $data['message']);
    }

    public function testCrossInstituteAccessPrevention(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherInstitute = new Institute();
        $otherInstituteId = Uuid::v7();
        $reflection = new \ReflectionClass($otherInstitute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($otherInstitute, $otherInstituteId);
        $otherInstitute->setName('Other Institute');
        $otherInstitute->setCode('OTHR' . $this->unique);
        $otherInstitute->setEmail('other-' . $this->unique . '@edupulse.ai');
        $otherInstitute->setStatus(InstituteStatus::Active);

        $otherPaper = new QuestionPaper();
        $otherPaperId = Uuid::v7();
        $qpReflection = new \ReflectionClass($otherPaper);
        $qpProperty = $qpReflection->getProperty('id');
        $qpProperty->setAccessible(true);
        $qpProperty->setValue($otherPaper, $otherPaperId);
        $otherPaper->setInstitute($otherInstitute);
        $otherPaper->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $otherPaper->setSubject($entityManager->getRepository(Subject::class)->find($this->testSubjectId));
        $otherPaper->setTitle('Other Paper ' . $this->unique);
        $otherPaper->setDate(new \DateTimeImmutable('2025-05-01'));
        $otherPaper->setDuration(60);
        $otherPaper->setMaximumMarks(100);
        $otherPaper->setStatus(QuestionPaperStatus::Finalized);

        $entityManager->persist($otherInstitute);
        $entityManager->persist($otherPaper);
        $entityManager->flush();

        $payload = json_encode([
            'questionPaperId' => (string) $otherPaperId,
            'title' => 'Cross Institute Session ' . $this->unique,
            'availableFrom' => '01-06-2027 09:00',
            'availableTo' => '01-06-2027 12:00',
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'sectionIds' => [(string) $this->testSectionId],
            'totalMarks' => 100,
            'status' => 'DRAFT',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('does not belong to the authenticated institute', strtolower($data['message']));
    }

    public function testSchedulingValidation(): void
    {
        $client = $this->client;

        $sessionId = $this->createTestExamSession($this->superAdminToken);

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/schedule', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('SCHEDULED', $data['data']['status']);
    }

    public function testPublishingValidation(): void
    {
        $client = $this->client;

        $sessionId = $this->createTestExamSession($this->superAdminToken);

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/schedule', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/publish', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('PUBLISHED', $data['data']['status']);
        $this->assertNotNull($data['data']['publishedAt']);
    }

    public function testCannotCreateExamSessionWithInvalidDates(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
            'title' => 'Invalid Dates Session ' . $this->unique,
            'availableFrom' => '01-06-2027 12:00',
            'availableTo' => '01-06-2027 09:00',
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'sectionIds' => [(string) $this->testSectionId],
            'totalMarks' => 100,
            'status' => 'DRAFT',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('availableTo must be after availableFrom', $data['message']);
    }

    public function testCreateExamSessionWithValidDatetimeFormat(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
            'title' => 'Valid Datetime Session ' . $this->unique,
            'availableFrom' => '23-08-2026 18:43',
            'availableTo' => '30-08-2026 18:43',
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'sectionIds' => [(string) $this->testSectionId],
            'totalMarks' => 100,
            'status' => 'DRAFT',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('2026-08-23T18:43:00+00:00', $data['data']['availableFrom']);
        $this->assertSame('2026-08-30T18:43:00+00:00', $data['data']['availableTo']);
    }

    public function testCreateExamSessionWithInvalidDatetimeFormat(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
            'title' => 'Invalid Datetime Session ' . $this->unique,
            'availableFrom' => 'invalid',
            'availableTo' => '30-08-2026 18:43',
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'sectionIds' => [(string) $this->testSectionId],
            'totalMarks' => 100,
            'status' => 'DRAFT',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('not a valid datetime', $data['errors']['availableFrom'][0]);
    }

    public function testCreateExamSessionWithInvalidCalendarDate(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
            'title' => 'Invalid Calendar Date Session ' . $this->unique,
            'availableFrom' => '31-02-2026 18:43',
            'availableTo' => '30-08-2026 18:43',
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'sectionIds' => [(string) $this->testSectionId],
            'totalMarks' => 100,
            'status' => 'DRAFT',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('availableFrom', $data['errors']);
        $this->assertStringContainsString('not a valid datetime', $data['errors']['availableFrom'][0]);
    }

    public function testCreateExamSessionWithWrongDatetimeSeparator(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
            'title' => 'Wrong Separator Session ' . $this->unique,
            'availableFrom' => '23/08/2026 18:43',
            'availableTo' => '30-08-2026 18:43',
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'sectionIds' => [(string) $this->testSectionId],
            'totalMarks' => 100,
            'status' => 'DRAFT',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('availableFrom', $data['errors']);
        $this->assertStringContainsString('not a valid datetime', $data['errors']['availableFrom'][0]);
    }

    public function testCreateExamSessionWithMissingTime(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
            'title' => 'Missing Time Session ' . $this->unique,
            'availableFrom' => '23-08-2026',
            'availableTo' => '30-08-2026 18:43',
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'sectionIds' => [(string) $this->testSectionId],
            'totalMarks' => 100,
            'status' => 'DRAFT',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('availableFrom', $data['errors']);
        $this->assertStringContainsString('not a valid datetime', $data['errors']['availableFrom'][0]);
    }

    public function testInstituteCanCreateExamSession(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
            'title' => 'Institute Exam Session ' . $this->unique,
            'availableFrom' => '01-06-2027 09:00',
            'availableTo' => '01-06-2027 12:00',
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'sectionIds' => [(string) $this->testSectionId],
            'totalMarks' => 100,
            'status' => 'DRAFT',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($this->instituteToken), $payload);

        $this->assertSame(201, $client->getResponse()->getStatusCode());
    }

    public function testTeacherCanCreateExamSession(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
            'title' => 'Teacher Exam Session ' . $this->unique,
            'availableFrom' => '01-06-2027 09:00',
            'availableTo' => '01-06-2027 12:00',
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'sectionIds' => [(string) $this->testSectionId],
            'totalMarks' => 100,
            'status' => 'DRAFT',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($this->teacherToken), $payload);

        $this->assertSame(201, $client->getResponse()->getStatusCode());
    }

    public function testInstituteCanListExamSessions(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($this->instituteToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testTeacherCanListExamSessions(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($this->teacherToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testRoleWithoutExamSessionPermissionReceives403(): void
    {
        $client = $this->client;

        $student = new User();
        $student->setEmail('student-exam-session-rbac-test-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($this->client->getContainer()->get('doctrine')->getManager()->getRepository(Institute::class)->find($this->testInstituteId));

        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        $entityManager->persist($student);
        $entityManager->flush();

        $studentToken = $this->login($client, 'student-exam-session-rbac-test-' . $this->unique . '@edupulse.ai', 'Student123!');

        $payload = json_encode([
            'questionPaperId' => (string) $this->testQuestionPaperId,
            'title' => 'Unauthorized Session ' . $this->unique,
            'availableFrom' => '01-06-2027 09:00',
            'availableTo' => '01-06-2027 12:00',
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'sectionIds' => [(string) $this->testSectionId],
            'totalMarks' => 100,
            'status' => 'DRAFT',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($studentToken), $payload);

        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [403, 404], 'Unauthorized role should receive 403 or 404.');
    }

    public function testCreateExamSessionWithKolkataTimezone(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $kolkataInstitute = new Institute();
        $kolkataInstituteId = Uuid::v7();
        $reflection = new \ReflectionClass($kolkataInstitute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($kolkataInstitute, $kolkataInstituteId);
        $kolkataInstitute->setName('Kolkata Test Institute');
        $kolkataInstitute->setCode('KOL' . $this->unique);
        $kolkataInstitute->setEmail('kolkata-test-' . $this->unique . '@edupulse.ai');
        $kolkataInstitute->setStatus(InstituteStatus::Active);
        $kolkataInstitute->setTimezone(\App\Enum\Timezone::INR);

        $kolkataYear = new AcademicYear();
        $kolkataYearId = Uuid::v7();
        $ayReflection = new \ReflectionClass($kolkataYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($kolkataYear, $kolkataYearId);
        $kolkataYear->setInstitute($kolkataInstitute);
        $kolkataYear->setName('Kolkata AY ' . $this->unique);
        $kolkataYear->setCode('KAY' . $this->unique);
        $kolkataYear->setStartDate(new \DateTimeImmutable('2025-01-01'));
        $kolkataYear->setEndDate(new \DateTimeImmutable('2025-12-31'));
        $kolkataYear->setStatus(AcademicYearStatus::Active);
        $kolkataYear->setIsActive(true);

        $kolkataClass = new AcademicClass();
        $kolkataClassId = Uuid::v7();
        $classReflection = new \ReflectionClass($kolkataClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($kolkataClass, $kolkataClassId);
        $kolkataClass->setInstitute($kolkataInstitute);
        $kolkataClass->setAcademicYear($kolkataYear);
        $kolkataClass->setName('Kolkata Class ' . $this->unique);
        $kolkataClass->setCode('KCLS' . $this->unique);
        $kolkataClass->setStatus(ClassStatus::Active);

        $kolkataSection = new Section();
        $kolkataSectionId = Uuid::v7();
        $sectionReflection = new \ReflectionClass($kolkataSection);
        $sectionProperty = $sectionReflection->getProperty('id');
        $sectionProperty->setAccessible(true);
        $sectionProperty->setValue($kolkataSection, $kolkataSectionId);
        $kolkataSection->setAcademicClass($kolkataClass);
        $kolkataSection->setName('Kolkata Section ' . $this->unique);
        $kolkataSection->setCode('KSEC' . $this->unique);
        $kolkataSection->setStatus(SectionStatus::Active);

        $kolkataPaper = new QuestionPaper();
        $kolkataPaperId = Uuid::v7();
        $qpReflection = new \ReflectionClass($kolkataPaper);
        $qpProperty = $qpReflection->getProperty('id');
        $qpProperty->setAccessible(true);
        $qpProperty->setValue($kolkataPaper, $kolkataPaperId);
        $kolkataPaper->setInstitute($kolkataInstitute);
        $kolkataPaper->setAcademicYear($kolkataYear);
        $kolkataPaper->setSubject($entityManager->getRepository(Subject::class)->find($this->testSubjectId));
        $kolkataPaper->setTitle('Kolkata Paper ' . $this->unique);
        $kolkataPaper->setDate(new \DateTimeImmutable('2025-05-01'));
        $kolkataPaper->setDuration(60);
        $kolkataPaper->setMaximumMarks(100);
        $kolkataPaper->setStatus(QuestionPaperStatus::Finalized);

        $entityManager->persist($kolkataInstitute);
        $entityManager->persist($kolkataYear);
        $entityManager->persist($kolkataClass);
        $entityManager->persist($kolkataSection);
        $entityManager->persist($kolkataPaper);
        $entityManager->flush();

        $payload = json_encode([
            'questionPaperId' => (string) $kolkataPaperId,
            'title' => 'Kolkata Timezone Session ' . $this->unique,
            'availableFrom' => '23-08-2026 20:00',
            'availableTo' => '30-08-2026 18:43',
            'academicYearId' => (string) $kolkataYearId,
            'academicClassId' => (string) $kolkataClassId,
            'sectionIds' => [(string) $kolkataSectionId],
            'totalMarks' => 100,
            'status' => 'DRAFT',
            'instituteId' => (string) $kolkataInstituteId,
        ]);

        $client->request('POST', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('2026-08-23T20:00:00+05:30', $data['data']['availableFrom']);
        $this->assertSame('2026-08-30T18:43:00+05:30', $data['data']['availableTo']);
    }

    public function testCreateExamSessionWithNewYorkTimezone(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $nyInstitute = new Institute();
        $nyInstituteId = Uuid::v7();
        $reflection = new \ReflectionClass($nyInstitute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($nyInstitute, $nyInstituteId);
        $nyInstitute->setName('NY Test Institute');
        $nyInstitute->setCode('NY' . $this->unique);
        $nyInstitute->setEmail('ny-test-' . $this->unique . '@edupulse.ai');
        $nyInstitute->setStatus(InstituteStatus::Active);
        $nyInstitute->setTimezone(\App\Enum\Timezone::USD);

        $nyYear = new AcademicYear();
        $nyYearId = Uuid::v7();
        $ayReflection = new \ReflectionClass($nyYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($nyYear, $nyYearId);
        $nyYear->setInstitute($nyInstitute);
        $nyYear->setName('NY AY ' . $this->unique);
        $nyYear->setCode('NAY' . $this->unique);
        $nyYear->setStartDate(new \DateTimeImmutable('2025-01-01'));
        $nyYear->setEndDate(new \DateTimeImmutable('2025-12-31'));
        $nyYear->setStatus(AcademicYearStatus::Active);
        $nyYear->setIsActive(true);

        $nyClass = new AcademicClass();
        $nyClassId = Uuid::v7();
        $classReflection = new \ReflectionClass($nyClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($nyClass, $nyClassId);
        $nyClass->setInstitute($nyInstitute);
        $nyClass->setAcademicYear($nyYear);
        $nyClass->setName('NY Class ' . $this->unique);
        $nyClass->setCode('NCLS' . $this->unique);
        $nyClass->setStatus(ClassStatus::Active);

        $nySection = new Section();
        $nySectionId = Uuid::v7();
        $sectionReflection = new \ReflectionClass($nySection);
        $sectionProperty = $sectionReflection->getProperty('id');
        $sectionProperty->setAccessible(true);
        $sectionProperty->setValue($nySection, $nySectionId);
        $nySection->setAcademicClass($nyClass);
        $nySection->setName('NY Section ' . $this->unique);
        $nySection->setCode('NSEC' . $this->unique);
        $nySection->setStatus(SectionStatus::Active);

        $nyPaper = new QuestionPaper();
        $nyPaperId = Uuid::v7();
        $qpReflection = new \ReflectionClass($nyPaper);
        $qpProperty = $qpReflection->getProperty('id');
        $qpProperty->setAccessible(true);
        $qpProperty->setValue($nyPaper, $nyPaperId);
        $nyPaper->setInstitute($nyInstitute);
        $nyPaper->setAcademicYear($nyYear);
        $nyPaper->setSubject($entityManager->getRepository(Subject::class)->find($this->testSubjectId));
        $nyPaper->setTitle('NY Paper ' . $this->unique);
        $nyPaper->setDate(new \DateTimeImmutable('2025-05-01'));
        $nyPaper->setDuration(60);
        $nyPaper->setMaximumMarks(100);
        $nyPaper->setStatus(QuestionPaperStatus::Finalized);

        $entityManager->persist($nyInstitute);
        $entityManager->persist($nyYear);
        $entityManager->persist($nyClass);
        $entityManager->persist($nySection);
        $entityManager->persist($nyPaper);
        $entityManager->flush();

        $payload = json_encode([
            'questionPaperId' => (string) $nyPaperId,
            'title' => 'NY Timezone Session ' . $this->unique,
            'availableFrom' => '23-08-2026 20:00',
            'availableTo' => '30-08-2026 18:43',
            'academicYearId' => (string) $nyYearId,
            'academicClassId' => (string) $nyClassId,
            'sectionIds' => [(string) $nySectionId],
            'totalMarks' => 100,
            'status' => 'DRAFT',
            'instituteId' => (string) $nyInstituteId,
        ]);

        $client->request('POST', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('2026-08-23T20:00:00-04:00', $data['data']['availableFrom']);
        $this->assertSame('2026-08-30T18:43:00-04:00', $data['data']['availableTo']);
    }

    public function testStudentCanListEligibleExamSessions(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Create a student with enrollment
        $student = new User();
        $studentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($student);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($student, $studentId);
        $student->setEmail('student-session-list-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $enrollment = new \App\Entity\StudentEnrollment();
        $enrollment->setStudent($student);
        $enrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment->setAdmissionNumber('ADM-STUDENT-' . $this->unique);
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $enrollment->setInstituteId($this->testInstituteId);

        $enrollmentSection = new \App\Entity\EnrollmentSection();
        $enrollmentSection->setStudentEnrollment($enrollment);
        $enrollmentSection->setSection($entityManager->getRepository(Section::class)->find($this->testSectionId));

        $entityManager->persist($student);
        $entityManager->persist($enrollment);
        $entityManager->persist($enrollmentSection);
        $entityManager->flush();

        $studentToken = $this->login($client, 'student-session-list-' . $this->unique . '@edupulse.ai', 'Student123!');

        $client->request('GET', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($studentToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertArrayHasKey('pagination', $data['data']);
    }

    public function testStudentCannotAccessUneligibleSession(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Create a student without enrollment
        $student = new User();
        $studentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($student);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($student, $studentId);
        $student->setEmail('student-no-enroll-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $entityManager->persist($student);
        $entityManager->flush();

        $studentToken = $this->login($client, 'student-no-enroll-' . $this->unique . '@edupulse.ai', 'Student123!');

        // Get an exam session ID
        $examSession = $entityManager->getRepository(ExamSession::class)->findOneBy([]);
        if ($examSession) {
            $client->request('GET', '/api/v1/exam-sessions/' . $examSession->getId()->toRfc4122(), [], [], $this->getAuthHeaders($studentToken));

            $response = $client->getResponse();
            $this->assertSame(404, $response->getStatusCode());
        }
    }

    public function testStudentWithNoSectionCanSeeClassLevelExam(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Create a student with enrollment but NO section
        $student = new User();
        $studentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($student);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($student, $studentId);
        $student->setEmail('student-no-section-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        // Create enrollment WITHOUT section
        $enrollment = new \App\Entity\StudentEnrollment();
        $enrollment->setStudent($student);
        $enrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment->setAdmissionNumber('ADM-NO-SECTION-' . $this->unique);
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $enrollment->setInstituteId($this->testInstituteId);

        $entityManager->persist($student);
        $entityManager->persist($enrollment);
        $entityManager->flush();

        $studentToken = $this->login($client, 'student-no-section-' . $this->unique . '@edupulse.ai', 'Student123!');

        // Create an exam session with NO section restriction
        $examSession = new \App\Entity\ExamSession();
        $examSessionId = Uuid::v7();
        $esReflection = new \ReflectionClass($examSession);
        $esProperty = $esReflection->getProperty('id');
        $esProperty->setAccessible(true);
        $esProperty->setValue($examSession, $examSessionId);
        $examSession->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $examSession->setQuestionPaper($entityManager->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $examSession->setTitle('Class Level Exam ' . $this->unique);
        $examSession->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $examSession->setAvailableTo(new \DateTimeImmutable('+1 hour'));
        $examSession->setTotalMarks(100);
        $examSession->setStatus(\App\Enum\ExamSessionStatus::Active);
        $examSession->setCreatedBy($entityManager->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-session-test-' . $this->unique . '@edupulse.ai']));
        $examSession->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $examSession->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        // NO sections added - this is a class-level exam

        $entityManager->persist($examSession);
        $entityManager->flush();

        $client->request('GET', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($studentToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        $found = false;
        foreach ($data['data']['items'] as $item) {
            if ($item['id'] === (string) $examSessionId) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Student with no section should see class-level exam');
    }

    public function testStudentWithNoSectionCanSeeSectionSpecificExam(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Create a student with enrollment but NO section
        $student = new User();
        $studentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($student);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($student, $studentId);
        $student->setEmail('student-no-section-2-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $enrollment = new \App\Entity\StudentEnrollment();
        $enrollment->setStudent($student);
        $enrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment->setAdmissionNumber('ADM-NO-SECTION-2-' . $this->unique);
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $enrollment->setInstituteId($this->testInstituteId);

        $entityManager->persist($student);
        $entityManager->persist($enrollment);
        $entityManager->flush();

        $studentToken = $this->login($client, 'student-no-section-2-' . $this->unique . '@edupulse.ai', 'Student123!');

        // Create an exam session WITH section restriction
        $examSession = new \App\Entity\ExamSession();
        $examSessionId = Uuid::v7();
        $esReflection = new \ReflectionClass($examSession);
        $esProperty = $esReflection->getProperty('id');
        $esProperty->setAccessible(true);
        $esProperty->setValue($examSession, $examSessionId);
        $examSession->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $examSession->setQuestionPaper($entityManager->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $examSession->setTitle('Section Specific Exam ' . $this->unique);
        $examSession->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $examSession->setAvailableTo(new \DateTimeImmutable('+1 hour'));
        $examSession->setTotalMarks(100);
        $examSession->setStatus(\App\Enum\ExamSessionStatus::Active);
        $examSession->setCreatedBy($entityManager->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-session-test-' . $this->unique . '@edupulse.ai']));
        $examSession->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $examSession->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));

        // Add section restriction
        $section = $entityManager->getRepository(Section::class)->find($this->testSectionId);
        if ($section) {
            $examSession->addSection($section);
        }

        $entityManager->persist($examSession);
        $entityManager->flush();

        $client->request('GET', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($studentToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        $found = false;
        foreach ($data['data']['items'] as $item) {
            if ($item['id'] === (string) $examSessionId) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Student with no section CAN see section-specific exam (class is the only requirement)');
    }

    public function testStudentOnlySeesExamsForTheirClass(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Get managed references
        $institute = $entityManager->getRepository(Institute::class)->find($this->testInstituteId);
        $academicYear = $entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId);
        $testClass = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);
        $questionPaper = $entityManager->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId);
        $superAdmin = $entityManager->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-session-test-' . $this->unique . '@edupulse.ai']);

        // Create a second class (Nursery)
        $nurseryClass = new AcademicClass();
        $nurseryClassId = Uuid::v7();
        $classReflection = new \ReflectionClass($nurseryClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($nurseryClass, $nurseryClassId);
        $nurseryClass->setInstitute($institute);
        $nurseryClass->setAcademicYear($academicYear);
        $nurseryClass->setName('Nursery');
        $nurseryClass->setCode('NUR' . $this->unique);
        $nurseryClass->setStatus(ClassStatus::Active);

        $entityManager->persist($nurseryClass);
        $entityManager->flush();

        // Re-fetch the nursery class to ensure it's managed by Doctrine
        $nurseryClass = $entityManager->getRepository(AcademicClass::class)->find($nurseryClassId);

        // Create student enrolled in test class (not nursery)
        $student = new User();
        $studentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($student);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($student, $studentId);
        $student->setEmail('student-class-filter-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $enrollment = new \App\Entity\StudentEnrollment();
        $enrollment->setStudent($student);
        $enrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment->setAdmissionNumber('ADM-CLASS-' . $this->unique);
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $enrollment->setInstituteId($this->testInstituteId);

        $entityManager->persist($student);
        $entityManager->persist($enrollment);
        $entityManager->flush();

        $studentToken = $this->login($client, 'student-class-filter-' . $this->unique . '@edupulse.ai', 'Student123!');

        // Create exam for test class (should be visible)
        $classExam = new \App\Entity\ExamSession();
        $classExamId = Uuid::v7();
        $esReflection = new \ReflectionClass($classExam);
        $esProperty = $esReflection->getProperty('id');
        $esProperty->setAccessible(true);
        $esProperty->setValue($classExam, $classExamId);
        $classExam->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $classExam->setQuestionPaper($entityManager->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $classExam->setTitle('Class Exam ' . $this->unique);
        $classExam->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $classExam->setAvailableTo(new \DateTimeImmutable('+1 hour'));
        $classExam->setTotalMarks(100);
        $classExam->setStatus(\App\Enum\ExamSessionStatus::Active);
        $classExam->setCreatedBy($entityManager->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-session-test-' . $this->unique . '@edupulse.ai']));
        $classExam->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $classExam->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));

        // Create exam for nursery class (should NOT be visible)
        $nurseryExam = new \App\Entity\ExamSession();
        $nurseryExamId = Uuid::v7();
        $esProperty->setValue($nurseryExam, $nurseryExamId);
        $nurseryExam->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $nurseryExam->setQuestionPaper($entityManager->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $nurseryExam->setTitle('Nursery Exam ' . $this->unique);
        $nurseryExam->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $nurseryExam->setAvailableTo(new \DateTimeImmutable('+1 hour'));
        $nurseryExam->setTotalMarks(100);
        $nurseryExam->setStatus(\App\Enum\ExamSessionStatus::Active);
        $nurseryExam->setCreatedBy($entityManager->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-session-test-' . $this->unique . '@edupulse.ai']));
        $nurseryExam->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $nurseryExam->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($nurseryClassId));

        $entityManager->persist($classExam);
        $entityManager->persist($nurseryExam);
        $entityManager->flush();

        $client->request('GET', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($studentToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        $foundClassExam = false;
        $foundNurseryExam = false;
        foreach ($data['data']['items'] as $item) {
            if ($item['id'] === (string) $classExamId) {
                $foundClassExam = true;
            }
            if ($item['id'] === (string) $nurseryExamId) {
                $foundNurseryExam = true;
            }
        }
        $this->assertTrue($foundClassExam, 'Student should see exam for their class');
        $this->assertFalse($foundNurseryExam, 'Student should NOT see exam for another class');
    }

    public function testStudentCannotAccessOtherClassExamById(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Create a second class (Nursery)
        $nurseryClass = new AcademicClass();
        $nurseryClassId = Uuid::v7();
        $classReflection = new \ReflectionClass($nurseryClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($nurseryClass, $nurseryClassId);
        $nurseryClass->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $nurseryClass->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $nurseryClass->setName('Nursery');
        $nurseryClass->setCode('NUR' . $this->unique);
        $nurseryClass->setStatus(ClassStatus::Active);

        $entityManager->persist($nurseryClass);
        $entityManager->flush();

        // Re-fetch the nursery class to ensure it's managed by Doctrine
        $nurseryClass = $entityManager->getRepository(AcademicClass::class)->find($nurseryClassId);

        // Create student enrolled in test class (not nursery)
        $student = new User();
        $studentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($student);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($student, $studentId);
        $student->setEmail('student-class-filter-2-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $enrollment = new \App\Entity\StudentEnrollment();
        $enrollment->setStudent($student);
        $enrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment->setAdmissionNumber('ADM-CLASS-2-' . $this->unique);
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $enrollment->setInstituteId($this->testInstituteId);

        $entityManager->persist($student);
        $entityManager->persist($enrollment);
        $entityManager->flush();

        $studentToken = $this->login($client, 'student-class-filter-2-' . $this->unique . '@edupulse.ai', 'Student123!');

        // Create exam for nursery class
        $nurseryExam = new \App\Entity\ExamSession();
        $nurseryExamId = Uuid::v7();
        $esReflection = new \ReflectionClass($nurseryExam);
        $esProperty = $esReflection->getProperty('id');
        $esProperty->setAccessible(true);
        $esProperty->setValue($nurseryExam, $nurseryExamId);
        $nurseryExam->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $nurseryExam->setQuestionPaper($entityManager->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $nurseryExam->setTitle('Nursery Exam 2 ' . $this->unique);
        $nurseryExam->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $nurseryExam->setAvailableTo(new \DateTimeImmutable('+1 hour'));
        $nurseryExam->setTotalMarks(100);
        $nurseryExam->setStatus(\App\Enum\ExamSessionStatus::Active);
        $nurseryExam->setCreatedBy($entityManager->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-session-test-' . $this->unique . '@edupulse.ai']));
        $nurseryExam->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $nurseryExam->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($nurseryClassId));

        $entityManager->persist($nurseryExam);
        $entityManager->flush();

        // Try to access nursery exam directly by ID
        $client->request('GET', '/api/v1/exam-sessions/' . $nurseryExamId, [], [], $this->getAuthHeaders($studentToken));

        $response = $client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testStudentCannotStartAttemptForOtherClassExam(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Create a second class (Nursery)
        $nurseryClass = new AcademicClass();
        $nurseryClassId = Uuid::v7();
        $classReflection = new \ReflectionClass($nurseryClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($nurseryClass, $nurseryClassId);
        $nurseryClass->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $nurseryClass->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $nurseryClass->setName('Nursery');
        $nurseryClass->setCode('NUR' . $this->unique);
        $nurseryClass->setStatus(ClassStatus::Active);

        $entityManager->persist($nurseryClass);
        $entityManager->flush();

        // Re-fetch the nursery class to ensure it's managed by Doctrine
        $nurseryClass = $entityManager->getRepository(AcademicClass::class)->find($nurseryClassId);

        // Create student enrolled in test class (not nursery)
        $student = new User();
        $studentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($student);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($student, $studentId);
        $student->setEmail('student-class-filter-3-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $enrollment = new \App\Entity\StudentEnrollment();
        $enrollment->setStudent($student);
        $enrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment->setAdmissionNumber('ADM-CLASS-3-' . $this->unique);
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $enrollment->setInstituteId($this->testInstituteId);

        $entityManager->persist($student);
        $entityManager->persist($enrollment);
        $entityManager->flush();

        $studentToken = $this->login($client, 'student-class-filter-3-' . $this->unique . '@edupulse.ai', 'Student123!');

        // Create exam for nursery class
        $nurseryExam = new \App\Entity\ExamSession();
        $nurseryExamId = Uuid::v7();
        $esReflection = new \ReflectionClass($nurseryExam);
        $esProperty = $esReflection->getProperty('id');
        $esProperty->setAccessible(true);
        $esProperty->setValue($nurseryExam, $nurseryExamId);
        $nurseryExam->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $nurseryExam->setQuestionPaper($entityManager->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $nurseryExam->setTitle('Nursery Exam 3 ' . $this->unique);
        $nurseryExam->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $nurseryExam->setAvailableTo(new \DateTimeImmutable('+1 hour'));
        $nurseryExam->setTotalMarks(100);
        $nurseryExam->setStatus(\App\Enum\ExamSessionStatus::Active);
        $nurseryExam->setCreatedBy($entityManager->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-session-test-' . $this->unique . '@edupulse.ai']));
        $nurseryExam->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $nurseryExam->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($nurseryClassId));

        $entityManager->persist($nurseryExam);
        $entityManager->flush();

        // Try to start attempt for nursery exam
        $client->request('POST', '/api/v1/exam-sessions/' . $nurseryExamId . '/attempts', [], [], $this->getAuthHeaders($studentToken));

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('not eligible', strtolower($data['message']));
    }

    public function testStudentWithNoSectionCanAccessClassLevelExamById(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $institute = $entityManager->getRepository(Institute::class)->find($this->testInstituteId);
        $academicYear = $entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId);
        $academicClass = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);
        $questionPaper = $entityManager->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId);
        $superAdmin = $entityManager->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-session-test-' . $this->unique . '@edupulse.ai']);

        $student = new User();
        $studentId = Uuid::v7();
        $studentRef = new \ReflectionClass($student);
        $studentProp = $studentRef->getProperty('id');
        $studentProp->setAccessible(true);
        $studentProp->setValue($student, $studentId);
        $student->setEmail('student-get-by-id-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($institute);

        $enrollment = new \App\Entity\StudentEnrollment();
        $eRef = new \ReflectionClass($enrollment);
        $eProp = $eRef->getProperty('id');
        $eProp->setAccessible(true);
        $eProp->setValue($enrollment, Uuid::v7());
        $enrollment->setStudent($student);
        $enrollment->setAcademicYear($academicYear);
        $enrollment->setAcademicClass($academicClass);
        $enrollment->setAdmissionNumber('ADM-GETID-' . $this->unique);
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $enrollment->setInstituteId($this->testInstituteId);

        $classLevelSession = new \App\Entity\ExamSession();
        $sessionId = Uuid::v7();
        $sRef = new \ReflectionClass($classLevelSession);
        $sProp = $sRef->getProperty('id');
        $sProp->setAccessible(true);
        $sProp->setValue($classLevelSession, $sessionId);
        $classLevelSession->setInstitute($institute);
        $classLevelSession->setQuestionPaper($questionPaper);
        $classLevelSession->setTitle('Class Level Exam GET ' . $this->unique);
        $classLevelSession->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $classLevelSession->setAvailableTo(new \DateTimeImmutable('+1 hour'));
        $classLevelSession->setTotalMarks(100);
        $classLevelSession->setStatus(\App\Enum\ExamSessionStatus::Active);
        $classLevelSession->setCreatedBy($superAdmin);
        $classLevelSession->setAcademicYear($academicYear);
        $classLevelSession->setAcademicClass($academicClass);

        $entityManager->persist($student);
        $entityManager->persist($enrollment);
        $entityManager->persist($classLevelSession);
        $entityManager->flush();

        $studentToken = $this->login($client, 'student-get-by-id-' . $this->unique . '@edupulse.ai', 'Student123!');

        $client->request('GET', '/api/v1/exam-sessions/' . $sessionId, [], [], $this->getAuthHeaders($studentToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame((string) $sessionId, $data['data']['id']);
    }
}
