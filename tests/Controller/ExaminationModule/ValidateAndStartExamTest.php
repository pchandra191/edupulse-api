<?php
declare(strict_types=1);

namespace App\Tests\Controller\ExaminationModule;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\ExamSession;
use App\Entity\ExamSubject;
use App\Entity\Examination;
use App\Entity\Institute;
use App\Entity\Question;
use App\Entity\QuestionOption;
use App\Entity\QuestionPaper;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\ExaminationStatus;
use App\Enum\InstituteStatus;
use App\Enum\QuestionPaperStatus;
use App\Enum\QuestionType;
use App\Enum\SectionStatus;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class ValidateAndStartExamTest extends WebTestCase
{
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private \Doctrine\ORM\EntityManagerInterface $entityManager;
    private array $createdEmails = [];
    private string $unique = '';

    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
    private ?Uuid $testClassId = null;
    private ?Uuid $testSectionId = null;
    private ?Uuid $testSubjectId = null;
    private ?Uuid $testSubject2Id = null;
    private ?Uuid $testExaminationId = null;
    private ?Uuid $testExamSubjectId = null;
    private ?Uuid $testExamSubject2Id = null;
    private ?Uuid $testQuestionPaperId = null;
    private ?Uuid $testQuestionPaper2Id = null;
    private ?string $superAdminToken = null;
    private ?string $instituteToken = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->unique = uniqid();
        $this->testInstituteId = Uuid::v7();
        $this->testAcademicYearId = Uuid::v7();
        $this->testClassId = Uuid::v7();
        $this->testSectionId = Uuid::v7();
        $this->testSubjectId = Uuid::v7();
        $this->testSubject2Id = Uuid::v7();
        $this->testExaminationId = Uuid::v7();
        $this->testExamSubjectId = Uuid::v7();
        $this->testExamSubject2Id = Uuid::v7();
        $this->testQuestionPaperId = Uuid::v7();
        $this->testQuestionPaper2Id = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-vs-' . $this->unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();
        $superAdmin->setFirstName('Super');
        $superAdmin->setLastName('Admin');

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute VS');
        $institute->setCode('TIVS' . $this->unique);
        $institute->setEmail('test-inst-vs-' . $this->unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);
        $institute->setTimezone(\App\Enum\Timezone::INR);

        $academicYear = new AcademicYear();
        $ayReflection = new \ReflectionClass($academicYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($academicYear, $this->testAcademicYearId);
        $academicYear->setInstitute($institute);
        $academicYear->setName('Test AY VS');
        $academicYear->setCode('AYVS' . $this->unique);
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
        $academicClass->setName('Test Class VS');
        $academicClass->setCode('CLVS' . $this->unique);
        $academicClass->setStatus(ClassStatus::Active);

        $section = new Section();
        $sectionReflection = new \ReflectionClass($section);
        $sectionProperty = $sectionReflection->getProperty('id');
        $sectionProperty->setAccessible(true);
        $sectionProperty->setValue($section, $this->testSectionId);
        $section->setAcademicClass($academicClass);
        $section->setName('Test Section VS');
        $section->setCode('SVC' . $this->unique);
        $section->setStatus(SectionStatus::Active);

        $subject = new Subject();
        $subjectReflection = new \ReflectionClass($subject);
        $subjectProperty = $subjectReflection->getProperty('id');
        $subjectProperty->setAccessible(true);
        $subjectProperty->setValue($subject, $this->testSubjectId);
        $subject->setInstitute($institute);
        $subject->setName('Test Subject VS');
        $subject->setCode('SVS' . $this->unique);
        $subject->setStatus(SubjectStatus::Active);

        $subject2 = new Subject();
        $subject2Reflection = new \ReflectionClass($subject2);
        $subject2Property = $subject2Reflection->getProperty('id');
        $subject2Property->setAccessible(true);
        $subject2Property->setValue($subject2, $this->testSubject2Id);
        $subject2->setInstitute($institute);
        $subject2->setName('Test Subject VS2');
        $subject2->setCode('SVS2' . $this->unique);
        $subject2->setStatus(SubjectStatus::Active);

        $examination = new Examination();
        $examination->setInstitute($institute);
        $examination->setAcademicYear($academicYear);
        $examination->setAcademicClass($academicClass);
        $examination->setName('Test Examination VS');
        $examination->setCode('TEVS' . $this->unique);
        $examination->setStartDate(new \DateTimeImmutable('2025-06-01'));
        $examination->setEndDate(new \DateTimeImmutable('2025-06-30'));
        $examination->setStatus(ExaminationStatus::Scheduled);

        $examReflection = new \ReflectionClass($examination);
        $examProperty = $examReflection->getProperty('id');
        $examProperty->setAccessible(true);
        $examProperty->setValue($examination, $this->testExaminationId);

        $examSubject = new ExamSubject();
        $examSubject->setExamination($examination);
        $examSubject->setSubject($subject);
        $examSubject->setSection($section);
        $examSubject->setExamDate(new \DateTimeImmutable('2025-12-01'));
        $examSubject->setStartTime(new \DateTimeImmutable('2025-12-01 09:00:00'));
        $examSubject->setEndTime(new \DateTimeImmutable('2025-12-01 11:00:00'));
        $examSubject->setDuration(120);
        $examSubject->setMaxMarks(100);
        $examSubject->setPassingMarks(40);
        $examSubject->setStatus(ExaminationStatus::Scheduled);

        $esReflection = new \ReflectionClass($examSubject);
        $esProperty = $esReflection->getProperty('id');
        $esProperty->setAccessible(true);
        $esProperty->setValue($examSubject, $this->testExamSubjectId);

        $examSubject2 = new ExamSubject();
        $examSubject2->setExamination($examination);
        $examSubject2->setSubject($subject2);
        $examSubject2->setSection(null);
        $examSubject2->setExamDate(new \DateTimeImmutable('2025-12-05'));
        $examSubject2->setStartTime(new \DateTimeImmutable('2025-12-05 10:00:00'));
        $examSubject2->setEndTime(new \DateTimeImmutable('2025-12-05 12:00:00'));
        $examSubject2->setDuration(120);
        $examSubject2->setMaxMarks(100);
        $examSubject2->setPassingMarks(35);
        $examSubject2->setStatus(ExaminationStatus::Scheduled);

        $es2Reflection = new \ReflectionClass($examSubject2);
        $es2Property = $es2Reflection->getProperty('id');
        $es2Property->setAccessible(true);
        $es2Property->setValue($examSubject2, $this->testExamSubject2Id);

        $instituteUser = new User();
        $instituteUser->setEmail('institute-vs-' . $this->unique . '@edupulse.ai');
        $instituteUser->setPassword(password_hash('Institute123!', PASSWORD_BCRYPT));
        $instituteUser->setRoles([UserRole::Institute->value]);
        $instituteUser->setStatus(UserStatus::Active);
        $instituteUser->verify();
        $instituteUser->setInstitute($institute);

        $this->entityManager->persist($superAdmin);
        $this->entityManager->persist($institute);
        $this->entityManager->persist($academicYear);
        $this->entityManager->persist($academicClass);
        $this->entityManager->persist($section);
        $this->entityManager->persist($subject);
        $this->entityManager->persist($subject2);
        $this->entityManager->persist($examination);
        $this->entityManager->persist($examSubject);
        $this->entityManager->persist($examSubject2);
        $this->entityManager->persist($instituteUser);
        $this->entityManager->flush();

        $this->createdEmails = [
            'superadmin-vs-' . $this->unique . '@edupulse.ai',
            'institute-vs-' . $this->unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login('superadmin-vs-' . $this->unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->instituteToken = $this->login('institute-vs-' . $this->unique . '@edupulse.ai', 'Institute123!');
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
            if ($entityManager->getConnection()->isTransactionActive()) {
                $entityManager->getConnection()->rollBack();
            }
        } catch (\Throwable $e) {
            if ($entityManager->getConnection()->isTransactionActive()) {
                $entityManager->getConnection()->rollBack();
            }
        }

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

    private function getAuthHeaders(?string $token): array
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        return $headers;
    }

    private function createQuestionPaper(Uuid $questionPaperId, Subject $subject, ?QuestionPaperStatus $status = QuestionPaperStatus::Finalized, int $totalMarks = 100, ?int $questionCount = 2): QuestionPaper
    {
        $em = $this->entityManager;

        $institute = $em->getRepository(Institute::class)->find($this->testInstituteId);
        $academicYear = $em->getRepository(AcademicYear::class)->find($this->testAcademicYearId);

        $questionPaper = new QuestionPaper();
        $qpReflection = new \ReflectionClass($questionPaper);
        $qpProperty = $qpReflection->getProperty('id');
        $qpProperty->setAccessible(true);
        $qpProperty->setValue($questionPaper, $questionPaperId);
        $questionPaper->setInstitute($institute);
        $questionPaper->setAcademicYear($academicYear);
        $questionPaper->setSubject($subject);
        $questionPaper->setTitle('Test QP ' . $questionPaperId->toRfc4122());
        $questionPaper->setDate(new \DateTimeImmutable('2025-05-01'));
        $questionPaper->setDuration(60);
        $questionPaper->setMaximumMarks($totalMarks);
        $questionPaper->setStatus($status);

        $em->persist($questionPaper);
        $em->flush();

        for ($i = 1; $i <= $questionCount; $i++) {
            $question = new Question();
            $question->setQuestionPaper($questionPaper);
            $question->setType(QuestionType::MCQ);
            $question->setQuestionText('Question ' . $i . ' for ' . $questionPaper->getTitle());
            $question->setMarks(5);
            $question->setDisplayOrder($i);

            $em->persist($question);

            $option = new QuestionOption();
            $option->setQuestion($question);
            $option->setOptionText('Option A');
            $option->setDisplayOrder(1);
            $option->setIsCorrect(true);
            $em->persist($option);
        }

        $em->flush();

        return $questionPaper;
    }

    private function attachQuestionPaper(Uuid $examSubjectId, Uuid $questionPaperId, ?string $token = null): array
    {
        $payload = json_encode([
            'questionPaperId' => (string) $questionPaperId,
        ]);

        $this->client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $examSubjectId, [], [], $this->getAuthHeaders($token ?? $this->superAdminToken), $payload);

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function callValidateAndStart(?string $token = null): array
    {
        $this->entityManager->clear();
        $payload = json_encode([]);
        $this->client->request('POST', '/api/v1/examinations/' . $this->testExaminationId . '/validate-and-start', [], [], $this->getAuthHeaders($token ?? $this->superAdminToken), $payload);

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function callReadiness(?string $token = null): array
    {
        $this->entityManager->clear();
        $this->client->request('GET', '/api/v1/examinations/' . $this->testExaminationId . '/readiness', [], [], $this->getAuthHeaders($token ?? $this->superAdminToken));

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function resetExamSubject(): void
    {
        $em = $this->entityManager;

        $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setQuestionPaper(null);
        $em->flush();

        $examSubject2 = $em->getRepository(ExamSubject::class)->find($this->testExamSubject2Id);
        $examSubject2->setQuestionPaper(null);
        $em->flush();

        $sessions = $em->getRepository(ExamSession::class)->findBy(['examination' => $this->testExaminationId]);
        foreach ($sessions as $session) {
            $em->remove($session);
        }
        $em->flush();
    }

    public function testExamSubjectWithoutQuestionPaperIsNotReady(): void
    {
        $data = $this->callReadiness();

        $this->assertTrue($data['success']);
        $this->assertFalse($data['data']['ready']);
        $this->assertGreaterThan(0, $data['data']['notReadySubjects']);

        foreach ($data['data']['subjects'] as $subject) {
            $this->assertSame('NOT_READY', $subject['readinessStatus']);
            $this->assertSame('QUESTION_PAPER_MISSING', $subject['readinessReason']);
        }
    }

    public function testDraftQuestionPaperIsNotReady(): void
    {
        $em = $this->entityManager;

        $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
        $paper = $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Draft);

        $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setQuestionPaper($paper);
        $em->flush();

        $data = $this->callReadiness();

        $this->assertTrue($data['success']);
        $this->assertFalse($data['data']['ready']);

        $foundNotReady = false;
        foreach ($data['data']['subjects'] as $subject) {
            if ((Uuid::fromString($subject['examSubjectId']))->equals($this->testExamSubjectId)) {
                $this->assertSame('NOT_READY', $subject['readinessStatus']);
                $this->assertSame('QUESTION_PAPER_NOT_FINALIZED', $subject['readinessReason']);
                $foundNotReady = true;
            }
        }
        $this->assertTrue($foundNotReady);
    }

    public function testFinalizedQuestionPaperIsReady(): void
    {
        $em = $this->entityManager;

        $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
        $subject2 = $em->getRepository(Subject::class)->find($this->testSubject2Id);
        $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 100, 2);
        $this->createQuestionPaper($this->testQuestionPaper2Id, $subject2, QuestionPaperStatus::Finalized, 100, 2);

        $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $em->flush();

        $examSubject2 = $em->getRepository(ExamSubject::class)->find($this->testExamSubject2Id);
        $examSubject2->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaper2Id));
        $em->flush();

        $data = $this->callReadiness();

        $this->assertTrue($data['success']);
        $this->assertTrue($data['data']['ready']);
        $this->assertSame(2, $data['data']['readySubjects']);
        $this->assertSame(0, $data['data']['notReadySubjects']);
        $this->assertTrue($data['data']['canValidateAndStart']);

        foreach ($data['data']['subjects'] as $subject) {
            $this->assertSame('READY', $subject['readinessStatus']);
        }
    }

    public function testInvalidMarksIsNotReady(): void
    {
        $em = $this->entityManager;

        $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
        $paper = $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 150, 2);

        $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setQuestionPaper($paper);
        $em->flush();

        $data = $this->callReadiness();

        $this->assertTrue($data['success']);
        $this->assertFalse($data['data']['ready']);

        $foundNotReady = false;
        foreach ($data['data']['subjects'] as $subject) {
            if ((Uuid::fromString($subject['examSubjectId']))->equals($this->testExamSubjectId)) {
                $this->assertSame('NOT_READY', $subject['readinessStatus']);
                $this->assertSame('MARKS_MISMATCH', $subject['readinessReason']);
                $foundNotReady = true;
            }
        }
        $this->assertTrue($foundNotReady);
    }

public function testMultipleSubjectsOneNotReadyValidationFailsAndNoSessionsCreated(): void
        {
            $em = $this->entityManager;

            $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
            $subject2 = $em->getRepository(Subject::class)->find($this->testSubject2Id);
            $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 100, 2);
            $this->createQuestionPaper($this->testQuestionPaper2Id, $subject2, QuestionPaperStatus::Draft, 100, 2);

            $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
            $examSubject->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
            $em->flush();

            $examSubject2 = $em->getRepository(ExamSubject::class)->find($this->testExamSubject2Id);
            $examSubject2->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaper2Id));
            $em->flush();

            $data = $this->callValidateAndStart();

            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $this->assertFalse($data['data']['success']);
            $this->assertSame(0, $data['data']['sessionsCreated']);
            $this->assertCount(1, $data['data']['errors']);

            $em->clear();
            $sessions = $em->getRepository(ExamSession::class)->findBy(['examination' => $this->testExaminationId]);
            $this->assertCount(0, $sessions);
        }

    public function testNotReadySubjectDoesNotCreatePublishedSession(): void
    {
        $em = $this->entityManager;

        $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
        $subject2 = $em->getRepository(Subject::class)->find($this->testSubject2Id);
        $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 100, 2);
        $this->createQuestionPaper($this->testQuestionPaper2Id, $subject2, QuestionPaperStatus::Draft, 100, 2);

        $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $em->flush();

        $examSubject2 = $em->getRepository(ExamSubject::class)->find($this->testExamSubject2Id);
        $examSubject2->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaper2Id));
        $em->flush();

        $data = $this->callValidateAndStart();

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($data['data']['success']);

        $em->clear();
        $sessions = $em->getRepository(ExamSession::class)->findBy(['examination' => $this->testExaminationId]);
        $this->assertCount(0, $sessions);

        // Verify no PUBLISHED sessions exist
        $publishedSessions = array_filter($sessions, fn($s) => $s->getStatus()->value === 'PUBLISHED');
        $this->assertCount(0, $publishedSessions);
    }

    public function testExistingDraftSessionBecomesPublished(): void
    {
        $em = $this->entityManager;

        $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
        $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 100, 2);

        // Remove the second exam subject to avoid validation errors
        $examSubject2 = $em->getRepository(ExamSubject::class)->find($this->testExamSubject2Id);
        $em->remove($examSubject2);
        $em->flush();

        $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $em->flush();

        // Manually create a DRAFT session
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'superadmin-vs-' . $this->unique . '@edupulse.ai']);

        $session = new \App\Entity\ExamSession();
        $session->setInstitute($em->getRepository(Institute::class)->find($this->testInstituteId));
        $session->setAcademicYear($em->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $session->setAcademicClass($em->getRepository(AcademicClass::class)->find($this->testClassId));
        $session->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $session->setExamination($em->getRepository(Examination::class)->find($this->testExaminationId));
        $session->setExamSubject($examSubject);
        $session->setCreatedBy($user);
        $session->setTitle('Test Session');
        $session->setStatus(\App\Enum\ExamSessionStatus::Draft);
        $session->setAvailableFrom(new \DateTimeImmutable('2025-06-15 09:00:00'));
        $session->setAvailableTo(new \DateTimeImmutable('2025-06-15 11:00:00'));
        $session->setDurationOverride(120);
        $session->setTotalMarks(100);
        $session->setShowResultsMode('IMMEDIATE');
        $session->setAccessMode('OPEN');

        $em->persist($session);
        $em->flush();

        $data = $this->callValidateAndStart();

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($data['data']['success']);

        $em->clear();
        $session = $em->getRepository(ExamSession::class)->findByExamSubject($this->testExamSubjectId, $this->testInstituteId);
        $this->assertNotNull($session);
        $this->assertSame('PUBLISHED', $session->getStatus()->value);
    }

public function testAllSubjectsReadySessionsCreated(): void
        {
            $em = $this->entityManager;

            $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
            $subject2 = $em->getRepository(Subject::class)->find($this->testSubject2Id);
            $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 100, 2);
            $this->createQuestionPaper($this->testQuestionPaper2Id, $subject2, QuestionPaperStatus::Finalized, 100, 2);

            $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
            $examSubject->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
            $em->flush();

            $examSubject2 = $em->getRepository(ExamSubject::class)->find($this->testExamSubject2Id);
            $examSubject2->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaper2Id));
            $em->flush();

            $data = $this->callValidateAndStart();

            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $this->assertTrue($data['data']['success']);
            $this->assertSame(2, $data['data']['sessionsCreated']);
            $this->assertCount(2, $data['data']['sessions']);

            $em->clear();
            $sessions = $em->getRepository(ExamSession::class)->findBy(['examination' => $this->testExaminationId]);
            $this->assertCount(2, $sessions);

            foreach ($data['data']['sessions'] as $session) {
                $this->assertTrue($session['created']);
                $this->assertFalse($session['reused']);
            }

            // Verify sessions are PUBLISHED
            foreach ($sessions as $session) {
                $this->assertSame('PUBLISHED', $session->getStatus()->value);
            }
        }

public function testCallingValidateAndStartTwiceNoDuplicateSessions(): void
        {
            $em = $this->entityManager;

            $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
            $subject2 = $em->getRepository(Subject::class)->find($this->testSubject2Id);
            $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 100, 2);
            $this->createQuestionPaper($this->testQuestionPaper2Id, $subject2, QuestionPaperStatus::Finalized, 100, 2);

            $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
            $examSubject->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
            $em->flush();

            $examSubject2 = $em->getRepository(ExamSubject::class)->find($this->testExamSubject2Id);
            $examSubject2->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaper2Id));
            $em->flush();

            $this->callValidateAndStart();

            $data = $this->callValidateAndStart();

            $this->assertTrue($data['data']['success']);
            $this->assertSame(0, $data['data']['sessionsCreated']);
            $this->assertSame(2, $data['data']['sessionsReused']);

            $em->clear();
            $sessions = $em->getRepository(ExamSession::class)->findBy(['examination' => $this->testExaminationId]);
            $this->assertCount(2, $sessions);

            // Verify sessions remain PUBLISHED
            foreach ($sessions as $session) {
                $this->assertSame('PUBLISHED', $session->getStatus()->value);
            }
        }

public function testExistingSessionReusedNotDuplicated(): void
        {
            $em = $this->entityManager;

            $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
            $subject2 = $em->getRepository(Subject::class)->find($this->testSubject2Id);
            $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 100, 2);
            $this->createQuestionPaper($this->testQuestionPaper2Id, $subject2, QuestionPaperStatus::Finalized, 100, 2);

            $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
            $examSubject->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
            $em->flush();

            $examSubject2 = $em->getRepository(ExamSubject::class)->find($this->testExamSubject2Id);
            $examSubject2->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaper2Id));
            $em->flush();

            $this->callValidateAndStart();

            $em->clear();
            $session = $em->getRepository(ExamSession::class)->findByExamSubject($this->testExamSubjectId, $this->testInstituteId);
            $originalSessionId = $session->getId();
            $this->assertSame('PUBLISHED', $session->getStatus()->value);

            $data = $this->callValidateAndStart();

            $this->assertTrue($data['data']['success']);
            $this->assertSame(0, $data['data']['sessionsCreated']);
            $this->assertSame(2, $data['data']['sessionsReused']);

            $em->clear();
            $session2 = $em->getRepository(ExamSession::class)->findByExamSubject($this->testExamSubjectId, $this->testInstituteId);
            $this->assertTrue($originalSessionId->equals($session2->getId()));
            $this->assertSame('PUBLISHED', $session2->getStatus()->value);
        }

    public function testUnauthorizedUserDenied(): void
    {
        $em = $this->entityManager;

        $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
        $subject2 = $em->getRepository(Subject::class)->find($this->testSubject2Id);
        $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 100, 2);
        $this->createQuestionPaper($this->testQuestionPaper2Id, $subject2, QuestionPaperStatus::Finalized, 100, 2);

        $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId)->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $em->getRepository(ExamSubject::class)->find($this->testExamSubject2Id)->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaper2Id));
        $em->flush();

        $otherInstitute = new Institute();
        $otherInstitute->setName('Other Institute VS');
        $otherInstitute->setCode('OI' . $this->unique);
        $otherInstitute->setEmail('other-vs-' . $this->unique . '@edupulse.ai');
        $otherInstitute->setStatus(InstituteStatus::Active);

        $otherUser = new User();
        $otherUser->setEmail('other-user-vs-' . $this->unique . '@edupulse.ai');
        $otherUser->setPassword(password_hash('Other123!', PASSWORD_BCRYPT));
        $otherUser->setRoles([UserRole::Institute->value]);
        $otherUser->setStatus(UserStatus::Active);
        $otherUser->verify();
        $otherUser->setInstitute($otherInstitute);

        $em->persist($otherInstitute);
        $em->persist($otherUser);
        $em->flush();

        $this->createdEmails[] = 'other-user-vs-' . $this->unique . '@edupulse.ai';

        $otherToken = $this->login('other-user-vs-' . $this->unique . '@edupulse.ai', 'Other123!');

        $this->client->request('POST', '/api/v1/examinations/' . $this->testExaminationId . '/validate-and-start', [], [], $this->getAuthHeaders($otherToken));

        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testArchivedExaminationCannotStart(): void
    {
        $em = $this->entityManager;

        $examination = $em->getRepository(Examination::class)->find($this->testExaminationId);
        $examination->setStatus(ExaminationStatus::Archived);
        $em->flush();

        $data = $this->callValidateAndStart();

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [400, 500]);
    }

    public function testTransactionRollbackWhenSessionCreationFails(): void
    {
        $em = $this->entityManager;

        $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
        $subject2 = $em->getRepository(Subject::class)->find($this->testSubject2Id);
        $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 100, 2);
        $this->createQuestionPaper($this->testQuestionPaper2Id, $subject2, QuestionPaperStatus::Finalized, 100, 2);

        $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $em->flush();

        $examSubject2 = $em->getRepository(ExamSubject::class)->find($this->testExamSubject2Id);
        $examSubject2->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaper2Id));
        $examSubject2->setStartTime(null);
        $examSubject2->setEndTime(null);
        $em->flush();

        $data = $this->callValidateAndStart();

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($data['data']['success']);
        $this->assertSame(0, $data['data']['sessionsCreated']);
        $this->assertCount(1, $data['data']['errors']);

        $em->clear();
        $sessions = $em->getRepository(ExamSession::class)->findBy(['examination' => $this->testExaminationId]);
        $this->assertCount(0, $sessions);
    }

    public function testGeneratedSessionContainsCorrectExamSubjectAndQuestionPaper(): void
    {
        $em = $this->entityManager;

        $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
        $subject2 = $em->getRepository(Subject::class)->find($this->testSubject2Id);
        $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 100, 2);
        $this->createQuestionPaper($this->testQuestionPaper2Id, $subject2, QuestionPaperStatus::Finalized, 100, 2);

        $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $em->flush();

        $examSubject2 = $em->getRepository(ExamSubject::class)->find($this->testExamSubject2Id);
        $examSubject2->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaper2Id));
        $em->flush();

        $data = $this->callValidateAndStart();

        $this->assertTrue($data['data']['success']);

        $em->clear();
        $session = $em->getRepository(ExamSession::class)->findByExamSubject($this->testExamSubjectId, $this->testInstituteId);
        $this->assertNotNull($session);
        $this->assertTrue($session->getExamSubject()->getId()->equals($this->testExamSubjectId));
        $this->assertTrue($session->getQuestionPaper()->getId()->equals($this->testQuestionPaperId));
        $this->assertSame(100, $session->getTotalMarks());
    }

    public function testExistingStudentExamSessionFunctionalityUnaffected(): void
    {
        $em = $this->entityManager;

        $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
        $subject2 = $em->getRepository(Subject::class)->find($this->testSubject2Id);
        $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 100, 2);
        $this->createQuestionPaper($this->testQuestionPaper2Id, $subject2, QuestionPaperStatus::Finalized, 100, 2);

        $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $em->flush();

        $examSubject2 = $em->getRepository(ExamSubject::class)->find($this->testExamSubject2Id);
        $examSubject2->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaper2Id));
        $em->flush();

        $this->callValidateAndStart();

        $em->clear();
        $session = $em->getRepository(ExamSession::class)->findByExamSubject($this->testExamSubjectId, $this->testInstituteId);
        $this->assertNotNull($session);
        $this->assertNotSame('DRAFT', $session->getStatus()->value);
        $this->assertGreaterThan(0, $session->getDurationOverride() ?? 0);

        $em->clear();
        $session2 = $em->getRepository(ExamSession::class)->find($session->getId());
        $this->assertNotNull($session2);
    }

    public function testCancelledSubjectDoesNotAffectReadiness(): void
    {
        $em = $this->entityManager;

        $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
        $subject2 = $em->getRepository(Subject::class)->find($this->testSubject2Id);
        $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 100, 2);
        $this->createQuestionPaper($this->testQuestionPaper2Id, $subject2, QuestionPaperStatus::Finalized, 100, 2);

        $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $em->flush();

        $examSubject2 = $em->getRepository(ExamSubject::class)->find($this->testExamSubject2Id);
        $examSubject2->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaper2Id));
        $examSubject2->setStatus(ExaminationStatus::Cancelled);
        $em->flush();

        $data = $this->callReadiness();

        $this->assertTrue($data['success']);
        $this->assertTrue($data['data']['ready']);
        $this->assertSame(1, $data['data']['activeSubjects']);
        $this->assertSame(1, $data['data']['cancelledSubjects']);
        $this->assertSame(1, $data['data']['readySubjects']);
        $this->assertSame(0, $data['data']['notReadySubjects']);
        $this->assertTrue($data['data']['canValidateAndStart']);

        $cancelledSubject = null;
        foreach ($data['data']['subjects'] as $subjectData) {
            if ((Uuid::fromString($subjectData['examSubjectId']))->equals($this->testExamSubject2Id)) {
                $cancelledSubject = $subjectData;
            }
        }

        $this->assertNotNull($cancelledSubject);
        $this->assertSame('CANCELLED', $cancelledSubject['examSubjectStatus']);
        $this->assertFalse($cancelledSubject['isActive']);
        $this->assertSame('EXCLUDED', $cancelledSubject['readinessStatus']);
        $this->assertFalse($cancelledSubject['isValidated']);
    }

    public function testArchivedSubjectDoesNotAffectReadiness(): void
    {
        $em = $this->entityManager;

        $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
        $subject2 = $em->getRepository(Subject::class)->find($this->testSubject2Id);
        $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 100, 2);
        $this->createQuestionPaper($this->testQuestionPaper2Id, $subject2, QuestionPaperStatus::Finalized, 100, 2);

        $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $em->flush();

        $examSubject2 = $em->getRepository(ExamSubject::class)->find($this->testExamSubject2Id);
        $examSubject2->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaper2Id));
        $examSubject2->setStatus(ExaminationStatus::Archived);
        $em->flush();

        $data = $this->callReadiness();

        $this->assertTrue($data['success']);
        $this->assertTrue($data['data']['ready']);
        $this->assertSame(1, $data['data']['activeSubjects']);
        $this->assertSame(0, $data['data']['cancelledSubjects']);
        $this->assertSame(1, $data['data']['readySubjects']);
        $this->assertSame(0, $data['data']['notReadySubjects']);
        $this->assertTrue($data['data']['canValidateAndStart']);
    }

    public function testTwoScheduledOneCancelledValidationCountIsTwo(): void
    {
        $em = $this->entityManager;

        $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
        $subject2 = $em->getRepository(Subject::class)->find($this->testSubject2Id);
        $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 100, 2);
        $this->createQuestionPaper($this->testQuestionPaper2Id, $subject2, QuestionPaperStatus::Finalized, 100, 2);

        $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $em->flush();

        $examSubject2 = $em->getRepository(ExamSubject::class)->find($this->testExamSubject2Id);
        $examSubject2->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaper2Id));
        $examSubject2->setStatus(ExaminationStatus::Cancelled);
        $em->flush();

        $subject3 = new Subject();
        $subject3Reflection = new \ReflectionClass($subject3);
        $subject3Property = $subject3Reflection->getProperty('id');
        $subject3Property->setAccessible(true);
        $subject3Property->setValue($subject3, Uuid::v7());
        $subject3->setInstitute($em->getRepository(Institute::class)->find($this->testInstituteId));
        $subject3->setName('Test Subject VS3');
        $subject3->setCode('SVS3' . $this->unique);
        $subject3->setStatus(SubjectStatus::Active);
        $em->persist($subject3);
        $em->flush();

        $examSubject3 = new ExamSubject();
        $examSubject3->setExamination($em->getRepository(Examination::class)->find($this->testExaminationId));
        $examSubject3->setSubject($subject3);
        $examSubject3->setSection(null);
        $examSubject3->setExamDate(new \DateTimeImmutable('2025-12-10'));
        $examSubject3->setStartTime(new \DateTimeImmutable('2025-12-10 10:00:00'));
        $examSubject3->setEndTime(new \DateTimeImmutable('2025-12-10 12:00:00'));
        $examSubject3->setDuration(120);
        $examSubject3->setMaxMarks(100);
        $examSubject3->setPassingMarks(35);
        $examSubject3->setStatus(ExaminationStatus::Scheduled);
        $em->persist($examSubject3);
        $em->flush();

        $subject3 = $em->getRepository(Subject::class)->find($subject3->getId());
        $questionPaper3 = $this->createQuestionPaper(Uuid::v7(), $subject3, QuestionPaperStatus::Finalized, 100, 2);
        $examSubject3->setQuestionPaper($questionPaper3);
        $em->flush();

        $data = $this->callReadiness();

        $this->assertTrue($data['success']);
        $this->assertSame(3, $data['data']['totalSubjects']);
        $this->assertSame(2, $data['data']['activeSubjects']);
        $this->assertSame(1, $data['data']['cancelledSubjects']);
        $this->assertSame(2, $data['data']['readySubjects']);
        $this->assertSame(0, $data['data']['notReadySubjects']);
        $this->assertTrue($data['data']['canValidateAndStart']);

        $data = $this->callValidateAndStart();

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($data['data']['success']);
        $this->assertSame(2, $data['data']['validatedSubjects']);
        $this->assertSame(2, $data['data']['totalSubjects']);
        $this->assertSame(2, $data['data']['sessionsCreated']);

        $em->clear();
        $sessions = $em->getRepository(ExamSession::class)->findBy(['examination' => $this->testExaminationId]);
        $this->assertCount(2, $sessions);

        foreach ($sessions as $session) {
            $this->assertNotSame(
                $this->testExamSubject2Id,
                $session->getExamSubject()->getId()->toRfc4122()
            );
        }
    }

    public function testCancelledSubjectDoesNotReceiveExamSession(): void
    {
        $em = $this->entityManager;

        $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
        $subject2 = $em->getRepository(Subject::class)->find($this->testSubject2Id);
        $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 100, 2);
        $this->createQuestionPaper($this->testQuestionPaper2Id, $subject2, QuestionPaperStatus::Finalized, 100, 2);

        $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $em->flush();

        $examSubject2 = $em->getRepository(ExamSubject::class)->find($this->testExamSubject2Id);
        $examSubject2->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaper2Id));
        $examSubject2->setStatus(ExaminationStatus::Cancelled);
        $em->flush();

        $data = $this->callValidateAndStart();

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($data['data']['success']);
        $this->assertSame(1, $data['data']['validatedSubjects']);
        $this->assertSame(1, $data['data']['totalSubjects']);
        $this->assertSame(1, $data['data']['sessionsCreated']);

        $em->clear();
        $sessions = $em->getRepository(ExamSession::class)->findBy(['examination' => $this->testExaminationId]);
        $this->assertCount(1, $sessions);

        foreach ($sessions as $session) {
            $this->assertNotSame(
                $this->testExamSubject2Id,
                $session->getExamSubject()->getId()->toRfc4122()
            );
        }
    }
}
