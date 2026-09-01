<?php
declare(strict_types=1);

namespace App\Tests\Controller\ExamAttemptSecurityEventModule;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\ExamAttempt;
use App\Entity\ExamAttemptSecurityEvent;
use App\Entity\ExamSession;
use App\Entity\Institute;
use App\Entity\Question;
use App\Entity\QuestionOption;
use App\Entity\QuestionPaper;
use App\Entity\Section;
use App\Entity\StudentEnrollment;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\ExamAttemptStatus;
use App\Enum\ExamSessionStatus;
use App\Enum\InstituteStatus;
use App\Enum\QuestionPaperStatus;
use App\Enum\QuestionType;
use App\Enum\SectionStatus;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class ExamAttemptSecurityEventControllerTest extends WebTestCase
{
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private array $createdEmails = [];
    private string $unique = '';

    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
    private ?Uuid $testClassId = null;
    private ?Uuid $testSectionId = null;
    private ?Uuid $testSubjectId = null;
    private ?Uuid $testQuestionPaperId = null;
    private ?Uuid $testExamSessionId = null;
    private ?Uuid $testAttemptId = null;
    private ?Uuid $testStudentId = null;
    private ?Uuid $testQuestionId = null;
    private ?Uuid $testOptionId = null;
    private ?string $studentToken = null;
    private ?Uuid $actualStudentId = null;
    private ?Uuid $actualSuperAdminId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $this->unique = uniqid();
        $this->testInstituteId = Uuid::v7();
        $this->testAcademicYearId = Uuid::v7();
        $this->testClassId = Uuid::v7();
        $this->testSectionId = Uuid::v7();
        $this->testSubjectId = Uuid::v7();
        $this->testQuestionPaperId = Uuid::v7();
        $this->testExamSessionId = Uuid::v7();
        $this->testAttemptId = Uuid::v7();
        $this->testStudentId = Uuid::v7();
        $this->testQuestionId = Uuid::v7();
        $this->testOptionId = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-security-test-' . $this->unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $student = new User();
        $student->setEmail('student-security-test-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for Security');
        $institute->setCode('TESTSEC' . $this->unique);
        $institute->setEmail('test-institute-security-' . $this->unique . '@edupulse.ai');
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
        $subject->setInstitute($institute);
        $subject->setName('Test Subject ' . $this->unique);
        $subject->setCode('SUB' . $this->unique);
        $subject->setStatus(SubjectStatus::Active);

        $questionPaper = new QuestionPaper();
        $qpReflection = new \ReflectionClass($questionPaper);
        $qpProperty = $qpReflection->getProperty('id');
        $qpProperty->setAccessible(true);
        $qpProperty->setValue($questionPaper, $this->testQuestionPaperId);
        $questionPaper->setInstitute($institute);
        $questionPaper->setAcademicYear($academicYear);
        $questionPaper->setSubject($subject);
        $questionPaper->setTitle('Test Paper ' . $this->unique);
        $questionPaper->setDate(new \DateTimeImmutable('2025-05-01'));
        $questionPaper->setDuration(60);
        $questionPaper->setMaximumMarks(100);
        $questionPaper->setStatus(QuestionPaperStatus::Finalized);

        $examSession = new ExamSession();
        $esReflection = new \ReflectionClass($examSession);
        $esProperty = $esReflection->getProperty('id');
        $esProperty->setAccessible(true);
        $esProperty->setValue($examSession, $this->testExamSessionId);
        $examSession->setInstitute($institute);
        $examSession->setAcademicYear($academicYear);
        $examSession->setAcademicClass($academicClass);
        $examSession->addSection($section);
        $examSession->setQuestionPaper($questionPaper);
        $examSession->setCreatedBy($superAdmin);
        $examSession->setTitle('Test Exam Session ' . $this->unique);
        $examSession->setAvailableFrom(new \DateTimeImmutable('2025-01-01 09:00:00'));
        $examSession->setAvailableTo(new \DateTimeImmutable('2025-12-31 23:59:59'));
        $examSession->setTotalMarks(100);
        $examSession->setStatus(ExamSessionStatus::Published);

        $question = new Question();
        $qReflection = new \ReflectionClass($question);
        $qProperty = $qReflection->getProperty('id');
        $qProperty->setAccessible(true);
        $qProperty->setValue($question, $this->testQuestionId);
        $question->setQuestionPaper($questionPaper);
        $question->setType(QuestionType::MCQ);
        $question->setQuestionText('Test Question');
        $question->setMarks(10);
        $question->setDisplayOrder(1);

        $option = new QuestionOption();
        $oReflection = new \ReflectionClass($option);
        $oProperty = $oReflection->getProperty('id');
        $oProperty->setAccessible(true);
        $oProperty->setValue($option, $this->testOptionId);
        $option->setQuestion($question);
        $option->setOptionText('Option 1');
        $option->setDisplayOrder(1);

        $attempt = new ExamAttempt();
        $aReflection = new \ReflectionClass($attempt);
        $aProperty = $aReflection->getProperty('id');
        $aProperty->setAccessible(true);
        $aProperty->setValue($attempt, $this->testAttemptId);
        $attempt->setExamSession($examSession);
        $attempt->setStudent($student);
        $attempt->setStartedAt(new \DateTimeImmutable('2025-01-01 10:00:00'));
        $attempt->setMode(\App\Enum\ExamAttemptMode::Online);
        $attempt->setStatus(ExamAttemptStatus::InProgress);

        $enrollment = new StudentEnrollment();
        $enrollment->setStudent($student);
        $enrollment->setAcademicYear($academicYear);
        $enrollment->setAdmissionNumber('ADM-' . $this->unique);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);

        $entityManager->persist($superAdmin);
        $entityManager->persist($student);
        $entityManager->persist($institute);
        $entityManager->persist($academicYear);
        $entityManager->persist($academicClass);
        $entityManager->persist($section);
        $entityManager->persist($subject);
        $entityManager->persist($questionPaper);
        $entityManager->persist($examSession);
        $entityManager->persist($question);
        $entityManager->persist($option);
        $entityManager->persist($attempt);
        $entityManager->flush();

        $this->actualStudentId = $student->getId();
        $this->actualSuperAdminId = $superAdmin->getId();

        $this->createdEmails = [
            'student-security-test-' . $this->unique . '@edupulse.ai',
            'superadmin-security-test-' . $this->unique . '@edupulse.ai',
        ];

        $this->studentToken = $this->login($student->getEmail(), 'Student123!');
    }

    protected function tearDown(): void
    {
        if (empty($this->createdEmails)) {
            parent::tearDown();
            return;
        }

        try {
            $entityManager = self::getContainer()->get('doctrine')->getManager();

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
        }

        $this->createdEmails = [];
        $this->unique = '';

        parent::tearDown();
    }

    private function login(string $email, string $password): ?string
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $email, 'password' => $password]));

        $response = $this->client->getResponse();
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

    private function sendSecurityEvent(array $payload, ?string $token = null): array
    {
        $client = $this->client;

        $client->request('POST', '/api/v1/exam-attempts/security-events', [], [], $this->getAuthHeaders($token ?? $this->studentToken), json_encode($payload));

        $responseContent = $client->getResponse()->getContent();

        return [
            'status' => $client->getResponse()->getStatusCode(),
            'content' => json_decode($responseContent, true),
        ];
    }

    public function testStudentCanRecordTabSwitchEvent(): void
    {
        $payload = [
            'attemptId' => $this->testAttemptId->toRfc4122(),
            'count' => 4,
            'eventType' => 'TAB_SWITCH',
            'examSessionId' => $this->testExamSessionId->toRfc4122(),
            'occurredAt' => '2025-01-01T10:05:00.000Z',
        ];

        $result = $this->sendSecurityEvent($payload);

        $this->assertSame(201, $result['status']);
        $this->assertTrue($result['content']['success']);
        $this->assertSame('TAB_SWITCH', $result['content']['data']['eventType']);
        $this->assertSame(4, $result['content']['data']['count']);
    }

    public function testStudentCanRecordFullscreenExitEvent(): void
    {
        $payload = [
            'attemptId' => $this->testAttemptId->toRfc4122(),
            'count' => 1,
            'eventType' => 'FULLSCREEN_EXIT',
            'examSessionId' => $this->testExamSessionId->toRfc4122(),
            'occurredAt' => '2025-01-01T10:06:00.000Z',
        ];

        $result = $this->sendSecurityEvent($payload);

        $this->assertSame(201, $result['status']);
        $this->assertTrue($result['content']['success']);
        $this->assertSame('FULLSCREEN_EXIT', $result['content']['data']['eventType']);
        $this->assertSame(1, $result['content']['data']['count']);
    }

    public function testStoredCountIsExactlySubmittedCount(): void
    {
        $payload = [
            'attemptId' => $this->testAttemptId->toRfc4122(),
            'count' => 4,
            'eventType' => 'TAB_SWITCH',
            'examSessionId' => $this->testExamSessionId->toRfc4122(),
            'occurredAt' => '2025-01-01T10:07:00.000Z',
        ];

        $result = $this->sendSecurityEvent($payload);

        $this->assertSame(201, $result['status']);

        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();
        $event = $entityManager->getRepository(ExamAttemptSecurityEvent::class)->findOneBy(['examAttempt' => $this->testAttemptId]);

        $this->assertNotNull($event);
        $this->assertSame(4, $event->getCount());
    }

    public function testStudentCannotCreateEventForAnotherStudentsAttempt(): void
    {
        $payload = [
            'attemptId' => $this->testAttemptId->toRfc4122(),
            'count' => 1,
            'eventType' => 'TAB_SWITCH',
            'examSessionId' => $this->testExamSessionId->toRfc4122(),
            'occurredAt' => '2025-01-01T10:08:00.000Z',
        ];

        $otherStudent = new User();
        $otherStudent->setEmail('other-student-security-test-' . $this->unique . '@edupulse.ai');
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();

        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();
        $entityManager->persist($otherStudent);
        $entityManager->flush();

        $otherToken = $this->login($otherStudent->getEmail(), 'Student123!');

        $result = $this->sendSecurityEvent($payload, $otherToken);

        $this->assertSame(404, $result['status']);
    }

    public function testMismatchedExamSessionIdIsRejected(): void
    {
        $wrongSessionId = Uuid::v7()->toRfc4122();

        $payload = [
            'attemptId' => $this->testAttemptId->toRfc4122(),
            'count' => 1,
            'eventType' => 'TAB_SWITCH',
            'examSessionId' => $wrongSessionId,
            'occurredAt' => '2025-01-01T10:09:00.000Z',
        ];

        $result = $this->sendSecurityEvent($payload);

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('Mismatched exam session', $result['content']['message']);
    }

    public function testInvalidEventTypeIsRejected(): void
    {
        $payload = [
            'attemptId' => $this->testAttemptId->toRfc4122(),
            'count' => 1,
            'eventType' => 'INVALID_EVENT',
            'examSessionId' => $this->testExamSessionId->toRfc4122(),
            'occurredAt' => '2025-01-01T10:10:00.000Z',
        ];

        $result = $this->sendSecurityEvent($payload);

        $this->assertSame(422, $result['status']);
    }

    public function testCountZeroIsRejected(): void
    {
        $payload = [
            'attemptId' => $this->testAttemptId->toRfc4122(),
            'count' => 0,
            'eventType' => 'TAB_SWITCH',
            'examSessionId' => $this->testExamSessionId->toRfc4122(),
            'occurredAt' => '2025-01-01T10:11:00.000Z',
        ];

        $result = $this->sendSecurityEvent($payload);

        $this->assertSame(422, $result['status']);
    }

    public function testNegativeCountIsRejected(): void
    {
        $payload = [
            'attemptId' => $this->testAttemptId->toRfc4122(),
            'count' => -1,
            'eventType' => 'TAB_SWITCH',
            'examSessionId' => $this->testExamSessionId->toRfc4122(),
            'occurredAt' => '2025-01-01T10:12:00.000Z',
        ];

        $result = $this->sendSecurityEvent($payload);

        $this->assertSame(422, $result['status']);
    }

    public function testMissingAttemptIdIsRejected(): void
    {
        $payload = [
            'count' => 1,
            'eventType' => 'TAB_SWITCH',
            'examSessionId' => $this->testExamSessionId->toRfc4122(),
            'occurredAt' => '2025-01-01T10:13:00.000Z',
        ];

        $result = $this->sendSecurityEvent($payload);

        $this->assertSame(422, $result['status']);
    }

    public function testMissingExamSessionIdIsRejected(): void
    {
        $payload = [
            'attemptId' => $this->testAttemptId->toRfc4122(),
            'count' => 1,
            'eventType' => 'TAB_SWITCH',
            'occurredAt' => '2025-01-01T10:14:00.000Z',
        ];

        $result = $this->sendSecurityEvent($payload);

        $this->assertSame(422, $result['status']);
    }

    public function testInvalidOccurredAtIsRejected(): void
    {
        $payload = [
            'attemptId' => $this->testAttemptId->toRfc4122(),
            'count' => 1,
            'eventType' => 'TAB_SWITCH',
            'examSessionId' => $this->testExamSessionId->toRfc4122(),
            'occurredAt' => 'not-a-date',
        ];

        $result = $this->sendSecurityEvent($payload);

        $this->assertSame(422, $result['status']);
    }

    public function testEventRejectedForSubmittedAttempt(): void
    {
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $attempt = $entityManager->getRepository(ExamAttempt::class)->find($this->testAttemptId);
        $attempt->setStatus(ExamAttemptStatus::Submitted);
        $attempt->setSubmittedAt(new \DateTimeImmutable('2025-01-01 10:30:00'));
        $entityManager->flush();

        $payload = [
            'attemptId' => $this->testAttemptId->toRfc4122(),
            'count' => 1,
            'eventType' => 'TAB_SWITCH',
            'examSessionId' => $this->testExamSessionId->toRfc4122(),
            'occurredAt' => '2025-01-01T10:15:00.000Z',
        ];

        $result = $this->sendSecurityEvent($payload);

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('active exam attempt', $result['content']['message']);
    }

    public function testOccurredAtIsPersistedCorrectly(): void
    {
        $occurredAt = '2025-01-01T10:16:00.000Z';

        $payload = [
            'attemptId' => $this->testAttemptId->toRfc4122(),
            'count' => 2,
            'eventType' => 'FULLSCREEN_EXIT',
            'examSessionId' => $this->testExamSessionId->toRfc4122(),
            'occurredAt' => $occurredAt,
        ];

        $result = $this->sendSecurityEvent($payload);

        $this->assertSame(201, $result['status']);
        $this->assertSame('2025-01-01T10:16:00+00:00', $result['content']['data']['occurredAt']);

        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();
        $event = $entityManager->getRepository(ExamAttemptSecurityEvent::class)->findOneBy(['examAttempt' => $this->testAttemptId]);

        $this->assertNotNull($event);
        $this->assertSame('2025-01-01T10:16:00+00:00', $event->getOccurredAt()->format('Y-m-d\TH:i:sP'));
    }

    public function testCreatedAtIsGeneratedByBackend(): void
    {
        $payload = [
            'attemptId' => $this->testAttemptId->toRfc4122(),
            'count' => 1,
            'eventType' => 'TAB_SWITCH',
            'examSessionId' => $this->testExamSessionId->toRfc4122(),
            'occurredAt' => '2025-01-01T10:17:00.000Z',
        ];

        $result = $this->sendSecurityEvent($payload);

        $this->assertSame(201, $result['status']);
        $this->assertNotEmpty($result['content']['data']['createdAt']);
        $this->assertIsString($result['content']['data']['createdAt']);
    }

    public function testEventAssociatedWithCorrectStudent(): void
    {
        $payload = [
            'attemptId' => $this->testAttemptId->toRfc4122(),
            'count' => 1,
            'eventType' => 'TAB_SWITCH',
            'examSessionId' => $this->testExamSessionId->toRfc4122(),
            'occurredAt' => '2025-01-01T10:18:00.000Z',
        ];

        $result = $this->sendSecurityEvent($payload);

        $this->assertSame(201, $result['status']);

        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();
        $event = $entityManager->getRepository(ExamAttemptSecurityEvent::class)->findOneBy(['examAttempt' => $this->testAttemptId]);

        $this->assertNotNull($event);
        $this->assertSame($this->actualStudentId->toRfc4122(), $event->getStudent()->getId()->toRfc4122());
    }

    public function testEventAssociatedWithCorrectExamSession(): void
    {
        $payload = [
            'attemptId' => $this->testAttemptId->toRfc4122(),
            'count' => 1,
            'eventType' => 'TAB_SWITCH',
            'examSessionId' => $this->testExamSessionId->toRfc4122(),
            'occurredAt' => '2025-01-01T10:19:00.000Z',
        ];

        $result = $this->sendSecurityEvent($payload);

        $this->assertSame(201, $result['status']);

        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();
        $event = $entityManager->getRepository(ExamAttemptSecurityEvent::class)->findOneBy(['examAttempt' => $this->testAttemptId]);

        $this->assertNotNull($event);
        $this->assertSame($this->testExamSessionId->toRfc4122(), $event->getExamSession()->getId()->toRfc4122());
    }

    public function testMultipleEventsForSameAttemptAreStoredIndependently(): void
    {
        $this->sendSecurityEvent([
            'attemptId' => $this->testAttemptId->toRfc4122(),
            'count' => 4,
            'eventType' => 'TAB_SWITCH',
            'examSessionId' => $this->testExamSessionId->toRfc4122(),
            'occurredAt' => '2025-01-01T10:20:00.000Z',
        ]);

        $this->sendSecurityEvent([
            'attemptId' => $this->testAttemptId->toRfc4122(),
            'count' => 1,
            'eventType' => 'FULLSCREEN_EXIT',
            'examSessionId' => $this->testExamSessionId->toRfc4122(),
            'occurredAt' => '2025-01-01T10:21:00.000Z',
        ]);

        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();
        $events = $entityManager->getRepository(ExamAttemptSecurityEvent::class)->findBy(['examAttempt' => $this->testAttemptId]);

        $this->assertCount(2, $events);

        $types = array_map(fn($e) => $e->getEventType()->value, $events);
        $this->assertContains('TAB_SWITCH', $types);
        $this->assertContains('FULLSCREEN_EXIT', $types);
    }
}
