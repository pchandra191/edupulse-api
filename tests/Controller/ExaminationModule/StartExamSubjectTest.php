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
use App\Enum\ExamSessionStatus;
use App\Enum\ExaminationStatus;
use App\Enum\InstituteStatus;
use App\Enum\QuestionPaperStatus;
use App\Enum\SectionStatus;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class StartExamSubjectTest extends WebTestCase
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
        $superAdmin->setEmail('superadmin-sx-' . $this->unique . '@edupulse.ai');
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
        $institute->setName('Test Institute SX');
        $institute->setCode('TISX' . $this->unique);
        $institute->setEmail('test-inst-sx-' . $this->unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);
        $institute->setTimezone(\App\Enum\Timezone::INR);

        $academicYear = new AcademicYear();
        $ayReflection = new \ReflectionClass($academicYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($academicYear, $this->testAcademicYearId);
        $academicYear->setInstitute($institute);
        $academicYear->setName('Test Academic Year SX');
        $academicYear->setCode('AYSX' . $this->unique);
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
        $academicClass->setName('Test Class SX');
        $academicClass->setCode('CLSX' . $this->unique);
        $academicClass->setStatus(ClassStatus::Active);

        $section = new Section();
        $sectionReflection = new \ReflectionClass($section);
        $sectionProperty = $sectionReflection->getProperty('id');
        $sectionProperty->setAccessible(true);
        $sectionProperty->setValue($section, $this->testSectionId);
        $section->setAcademicClass($academicClass);
        $section->setName('Test Section SX');
        $section->setCode('SSX' . $this->unique);
        $section->setStatus(SectionStatus::Active);

        $subject = new Subject();
        $subjectReflection = new \ReflectionClass($subject);
        $subjectProperty = $subjectReflection->getProperty('id');
        $subjectProperty->setAccessible(true);
        $subjectProperty->setValue($subject, $this->testSubjectId);
        $subject->setInstitute($institute);
        $subject->setName('Test Subject SX');
        $subject->setCode('SBSX' . $this->unique);
        $subject->setStatus(SubjectStatus::Active);

        $subject2 = new Subject();
        $subject2Reflection = new \ReflectionClass($subject2);
        $subject2Property = $subject2Reflection->getProperty('id');
        $subject2Property->setAccessible(true);
        $subject2Property->setValue($subject2, $this->testSubject2Id);
        $subject2->setInstitute($institute);
        $subject2->setName('Test Subject SX2');
        $subject2->setCode('SBSX2' . $this->unique);
        $subject2->setStatus(SubjectStatus::Active);

        $examination = new Examination();
        $examination->setInstitute($institute);
        $examination->setAcademicYear($academicYear);
        $examination->setAcademicClass($academicClass);
        $examination->setName('Test Examination SX');
        $examination->setCode('TESX' . $this->unique);
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
        $instituteUser->setEmail('institute-sx-' . $this->unique . '@edupulse.ai');
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
            'superadmin-sx-' . $this->unique . '@edupulse.ai',
            'institute-sx-' . $this->unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login('superadmin-sx-' . $this->unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->instituteToken = $this->login('institute-sx-' . $this->unique . '@edupulse.ai', 'Institute123!');
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
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->getConnection()->rollBack();
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
        $questionPaper->setTitle('Test QP SX ' . $questionPaperId->toRfc4122());
        $questionPaper->setDate(new \DateTimeImmutable('2025-05-01'));
        $questionPaper->setDuration(60);
        $questionPaper->setMaximumMarks($totalMarks);
        $questionPaper->setStatus($status);

        $em->persist($questionPaper);
        $em->flush();

        for ($i = 1; $i <= $questionCount; $i++) {
            $question = new Question();
            $question->setQuestionPaper($questionPaper);
            $question->setType(\App\Enum\QuestionType::MCQ);
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

    private function callStartExamSubject(string $examSubjectId, ?string $token = null): array
    {
        $this->entityManager->clear();
        $payload = json_encode([]);
        $this->client->request('POST', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $examSubjectId . '/start', [], [], $this->getAuthHeaders($token ?? $this->superAdminToken), $payload);

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testIndividualExamSubjectCanBeStartedIndependently(): void
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

        $data = $this->callStartExamSubject((string) $this->testExamSubjectId);

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($data['data']['success']);
        $this->assertNotNull($data['data']['examSession']);
        $this->assertSame('PUBLISHED', $data['data']['examSession']['status']);
    }

    public function testStartingOneSubjectDoesNotStartOtherSubject(): void
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

        $this->callStartExamSubject((string) $this->testExamSubjectId);

        $em->clear();
        $session1 = $em->getRepository(ExamSession::class)->findByExamSubject($this->testExamSubjectId, $this->testInstituteId);
        $session2 = $em->getRepository(ExamSession::class)->findByExamSubject($this->testExamSubject2Id, $this->testInstituteId);

        $this->assertNotNull($session1);
        $this->assertSame('PUBLISHED', $session1->getStatus()->value);
        $this->assertNull($session2);
    }

    public function testIndividualStartRequiresScheduledStatus(): void
    {
        $em = $this->entityManager;

        $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
        $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 100, 2);

        $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $examSubject->setStatus(ExaminationStatus::Draft);
        $em->flush();

        $data = $this->callStartExamSubject((string) $this->testExamSubjectId);

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($data['data']['success']);
        $this->assertCount(1, $data['data']['errors']);
        $this->assertSame('INVALID_CONFIGURATION', $data['data']['errors'][0]['reason']);
    }

    public function testIndividualStartRequiresFinalizedQuestionPaper(): void
    {
        $em = $this->entityManager;

        $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
        $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Draft, 100, 2);

        $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $em->flush();

        $data = $this->callStartExamSubject((string) $this->testExamSubjectId);

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($data['data']['success']);
        $this->assertCount(1, $data['data']['errors']);
        $this->assertSame('QUESTION_PAPER_NOT_FINALIZED', $data['data']['errors'][0]['reason']);
    }

    public function testIndividualStartCreatesPublishedExamSession(): void
    {
        $em = $this->entityManager;

        $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
        $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 100, 2);

        $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $em->flush();

        $data = $this->callStartExamSubject((string) $this->testExamSubjectId);

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($data['data']['success']);
        $this->assertNotNull($data['data']['examSession']);

        $em->clear();
        $session = $em->getRepository(ExamSession::class)->findByExamSubject($this->testExamSubjectId, $this->testInstituteId);
        $this->assertNotNull($session);
        $this->assertSame('PUBLISHED', $session->getStatus()->value);
    }

    public function testRepeatedIndividualStartDoesNotDuplicateSessions(): void
    {
        $em = $this->entityManager;

        $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
        $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 100, 2);

        $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $em->flush();

        $this->callStartExamSubject((string) $this->testExamSubjectId);

        $em->clear();
        $firstSession = $em->getRepository(ExamSession::class)->findByExamSubject($this->testExamSubjectId, $this->testInstituteId);
        $this->assertNotNull($firstSession);
        $firstSessionId = $firstSession->getId();

        $data = $this->callStartExamSubject((string) $this->testExamSubjectId);

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($data['data']['success']);
        $this->assertNotNull($data['data']['examSession']);
        $this->assertTrue($firstSessionId->equals(Uuid::fromString($data['data']['examSession']['id'])));

        $em->clear();
        $secondSession = $em->getRepository(ExamSession::class)->findByExamSubject($this->testExamSubjectId, $this->testInstituteId);
        $this->assertTrue($firstSessionId->equals($secondSession->getId()));
        $this->assertSame('PUBLISHED', $secondSession->getStatus()->value);
    }

    public function testIndividualStartReusesExistingSession(): void
    {
        $em = $this->entityManager;

        $subject = $em->getRepository(Subject::class)->find($this->testSubjectId);
        $this->createQuestionPaper($this->testQuestionPaperId, $subject, QuestionPaperStatus::Finalized, 100, 2);

        $examSubject = $em->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $em->flush();

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'superadmin-sx-' . $this->unique . '@edupulse.ai']);

        $session = new ExamSession();
        $session->setInstitute($em->getRepository(Institute::class)->find($this->testInstituteId));
        $session->setAcademicYear($em->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $session->setAcademicClass($em->getRepository(AcademicClass::class)->find($this->testClassId));
        $session->setQuestionPaper($em->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $session->setExamination($em->getRepository(Examination::class)->find($this->testExaminationId));
        $session->setExamSubject($examSubject);
        $session->setCreatedBy($user);
        $session->setTitle('Test Session SX');
        $session->setStatus(ExamSessionStatus::Draft);
        $session->setAvailableFrom(new \DateTimeImmutable('2025-06-15 09:00:00'));
        $session->setAvailableTo(new \DateTimeImmutable('2025-06-15 11:00:00'));
        $session->setDurationOverride(120);
        $session->setTotalMarks(100);
        $session->setShowResultsMode('IMMEDIATE');
        $session->setAccessMode('OPEN');

        $em->persist($session);
        $em->flush();

        $data = $this->callStartExamSubject((string) $this->testExamSubjectId);

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($data['data']['success']);
        $this->assertSame('PUBLISHED', $data['data']['examSession']['status']);
        $this->assertTrue($session->getId()->equals(Uuid::fromString($data['data']['examSession']['id'])));
    }
}
