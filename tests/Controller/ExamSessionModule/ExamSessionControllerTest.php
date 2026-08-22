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
        $entityManager->flush();

        $this->createdEmails = [
            'superadmin-exam-session-test-' . $this->unique . '@edupulse.ai',
            'institute-exam-session-test-' . $this->unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login($this->client, 'superadmin-exam-session-test-' . $this->unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->instituteToken = $this->login($this->client, 'institute-exam-session-test-' . $this->unique . '@edupulse.ai', 'InstituteUser123!');
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
            'availableFrom' => '2027-06-01 09:00:00',
            'availableTo' => '2027-06-01 12:00:00',
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
            'availableFrom' => '2027-06-01 09:00:00',
            'availableTo' => '2027-06-01 12:00:00',
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
            'availableFrom' => '2027-06-01 09:00:00',
            'availableTo' => '2027-06-01 12:00:00',
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
        $this->assertStringContainsString('Only draft exam sessions can be modified', $data['message']);
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
        $this->assertStringContainsString('Only draft exam sessions can be modified', $data['message']);
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
        $this->assertStringContainsString('Only draft exam sessions can be modified', $data['message']);
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
        $this->assertStringContainsString('Only draft exam sessions can be modified', $data['message']);
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
        $this->assertStringContainsString('Only draft exam sessions can be modified', $data['message']);
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
        $this->assertStringContainsString('Only draft exam sessions can be modified', $data['message']);
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
            'availableFrom' => '2027-06-01 09:00:00',
            'availableTo' => '2027-06-01 12:00:00',
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
            'availableFrom' => '2027-06-01 12:00:00',
            'availableTo' => '2027-06-01 09:00:00',
            'totalMarks' => 100,
            'status' => 'DRAFT',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('availableTo must be after availableFrom', $data['message']);
    }
}
