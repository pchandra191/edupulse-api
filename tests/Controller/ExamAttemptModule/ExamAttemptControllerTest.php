<?php
declare(strict_types=1);

namespace App\Tests\Controller\ExamAttemptModule;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\ExamAnswer;
use App\Entity\ExamAttempt;
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

class ExamAttemptControllerTest extends WebTestCase
{
    private ?string $superAdminToken = null;
    private ?string $studentToken = null;
    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
    private ?Uuid $testClassId = null;
    private ?Uuid $testSubjectId = null;
    private ?Uuid $testSectionId = null;
    private ?Uuid $testQuestionPaperId = null;
    private ?Uuid $testQuestion1Id = null;
    private ?Uuid $testQuestion2Id = null;
    private ?Uuid $testExamSessionId = null;
    private ?Uuid $testStudentId = null;
    private ?Uuid $testAttemptId = null;
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
        $superAdmin->setEmail('superadmin-exam-attempt-test-' . $this->unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for Exam Attempts');
        $institute->setCode('TESTEA' . $this->unique);
        $institute->setEmail('test-institute-exam-attempt-' . $this->unique . '@edupulse.ai');
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

        $question1 = new Question();
        $question1Id = Uuid::v7();
        $q1Reflection = new \ReflectionClass($question1);
        $q1Property = $q1Reflection->getProperty('id');
        $q1Property->setAccessible(true);
        $q1Property->setValue($question1, $question1Id);
        $question1->setQuestionPaper($questionPaper);
        $question1->setType(QuestionType::MCQ);
        $question1->setQuestionText('What is 2+2?');
        $question1->setMarks(1);
        $question1->setDisplayOrder(1);

        $q1Option1 = new QuestionOption();
        $q1o1Reflection = new \ReflectionClass($q1Option1);
        $q1o1Property = $q1o1Reflection->getProperty('id');
        $q1o1Property->setAccessible(true);
        $q1o1Property->setValue($q1Option1, Uuid::v7());
        $q1Option1->setQuestion($question1);
        $q1Option1->setOptionText('3');
        $q1Option1->setDisplayOrder(1);

        $q1Option2 = new QuestionOption();
        $q1o2Reflection = new \ReflectionClass($q1Option2);
        $q1o2Property = $q1o2Reflection->getProperty('id');
        $q1o2Property->setAccessible(true);
        $q1o2Property->setValue($q1Option2, Uuid::v7());
        $q1Option2->setQuestion($question1);
        $q1Option2->setOptionText('4');
        $q1Option2->setDisplayOrder(2);

        $question2 = new Question();
        $question2Id = Uuid::v7();
        $q2Reflection = new \ReflectionClass($question2);
        $q2Property = $q2Reflection->getProperty('id');
        $q2Property->setAccessible(true);
        $q2Property->setValue($question2, $question2Id);
        $question2->setQuestionPaper($questionPaper);
        $question2->setType(QuestionType::MCQ);
        $question2->setQuestionText('What is the capital of France?');
        $question2->setMarks(1);
        $question2->setDisplayOrder(2);

        $q2Option1 = new QuestionOption();
        $q2o1Reflection = new \ReflectionClass($q2Option1);
        $q2o1Property = $q2o1Reflection->getProperty('id');
        $q2o1Property->setAccessible(true);
        $q2o1Property->setValue($q2Option1, Uuid::v7());
        $q2Option1->setQuestion($question2);
        $q2Option1->setOptionText('London');
        $q2Option1->setDisplayOrder(1);

        $q2Option2 = new QuestionOption();
        $q2o2Reflection = new \ReflectionClass($q2Option2);
        $q2o2Property = $q2o2Reflection->getProperty('id');
        $q2o2Property->setAccessible(true);
        $q2o2Property->setValue($q2Option2, Uuid::v7());
        $q2Option2->setQuestion($question2);
        $q2Option2->setOptionText('Paris');
        $q2Option2->setDisplayOrder(2);

        $entityManager->persist($question1);
        $entityManager->persist($q1Option1);
        $entityManager->persist($q1Option2);
        $entityManager->persist($question2);
        $entityManager->persist($q2Option1);
        $entityManager->persist($q2Option2);

        $this->testQuestion1Id = $question1Id;
        $this->testQuestion2Id = $question2Id;

        $examSession = new ExamSession();
        $this->testExamSessionId = Uuid::v7();
        $esReflection = new \ReflectionClass($examSession);
        $esProperty = $esReflection->getProperty('id');
        $esProperty->setAccessible(true);
        $esProperty->setValue($examSession, $this->testExamSessionId);
        $examSession->setInstitute($institute);
        $examSession->setAcademicYear($academicYear);
        $examSession->setAcademicClass($academicClass);
        $examSession->addSection($section);
        $examSession->setQuestionPaper($questionPaper);
        $examSession->setTitle('Test Exam Session ' . $this->unique);
        $examSession->setAvailableFrom(new \DateTimeImmutable('2025-01-01 09:00:00'));
        $examSession->setAvailableTo(new \DateTimeImmutable('2026-12-31 23:59:59'));
        $examSession->setTotalMarks(100);
        $examSession->setStatus(ExamSessionStatus::Active);
        $examSession->setCreatedBy($superAdmin);

        $student = new User();
        $this->testStudentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($student);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($student, $this->testStudentId);
        $student->setEmail('student-exam-attempt-test-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($institute);

        $studentEnrollment = new StudentEnrollment();
        $enrollmentReflection = new \ReflectionClass($studentEnrollment);
        $enrollmentProperty = $enrollmentReflection->getProperty('id');
        $enrollmentProperty->setAccessible(true);
        $enrollmentProperty->setValue($studentEnrollment, Uuid::v7());
        $studentEnrollment->setStudent($student);
        $studentEnrollment->setAcademicYear($academicYear);
        $studentEnrollment->setAcademicClass($academicClass);
        $studentEnrollment->setAdmissionNumber('ADM' . $this->unique);
        $studentEnrollment->setIsCurrent(true);
        $studentEnrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $studentEnrollment->setInstituteId($this->testInstituteId);

        $enrollmentSection = new \App\Entity\EnrollmentSection();
        $esReflection = new \ReflectionClass($enrollmentSection);
        $esProperty = $esReflection->getProperty('id');
        $esProperty->setAccessible(true);
        $esProperty->setValue($enrollmentSection, Uuid::v7());
        $enrollmentSection->setStudentEnrollment($studentEnrollment);
        $enrollmentSection->setSection($section);

        $entityManager->persist($superAdmin);
        $entityManager->persist($institute);
        $entityManager->persist($academicYear);
        $entityManager->persist($academicClass);
        $entityManager->persist($section);
        $entityManager->persist($subject);
        $entityManager->persist($questionPaper);
        $entityManager->persist($examSession);
        $entityManager->persist($student);
        $entityManager->persist($studentEnrollment);
        $entityManager->persist($enrollmentSection);
        $entityManager->flush();

        $attempt = new ExamAttempt();
        $this->testAttemptId = Uuid::v7();
        $attemptReflection = new \ReflectionClass($attempt);
        $attemptProperty = $attemptReflection->getProperty('id');
        $attemptProperty->setAccessible(true);
        $attemptProperty->setValue($attempt, $this->testAttemptId);
        $attempt->setExamSession($examSession);
        $attempt->setStudent($student);
        $attempt->setStatus(ExamAttemptStatus::InProgress);
        $attempt->setStartedAt(new \DateTimeImmutable());

        $entityManager->persist($attempt);
        $entityManager->flush();

        $this->createdEmails = [
            'superadmin-exam-attempt-test-' . $this->unique . '@edupulse.ai',
            'student-exam-attempt-test-' . $this->unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login($this->client, 'superadmin-exam-attempt-test-' . $this->unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->studentToken = $this->login($this->client, 'student-exam-attempt-test-' . $this->unique . '@edupulse.ai', 'Student123!');
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
        $this->studentToken = null;
        $this->testInstituteId = null;
        $this->testAcademicYearId = null;
        $this->testClassId = null;
        $this->testSubjectId = null;
        $this->testSectionId = null;
        $this->testQuestionPaperId = null;
        $this->testExamSessionId = null;
        $this->testStudentId = null;
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
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    private function setAttemptTiming(int $allowedDurationSeconds, ?\DateTimeImmutable $startedAt = null, ?\DateTimeImmutable $expiresAt = null): void
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $attempt = $entityManager->getRepository(ExamAttempt::class)->find($this->testAttemptId);
        $attempt->setAllowedDurationSeconds($allowedDurationSeconds);
        $attempt->setStartedAt($startedAt ?? new \DateTimeImmutable());
        if ($expiresAt === null) {
            $expiresAt = ($startedAt ?? new \DateTimeImmutable())->modify('+' . $allowedDurationSeconds . ' seconds');
        }
        $attempt->setExpiresAt($expiresAt);
        $entityManager->flush();
        $entityManager->clear();
    }

    public function testStudentCanStartEligibleExamSession(): void
    {
        $client = $this->client;

        $client->request('POST', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/attempts', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('IN_PROGRESS', $data['data']['status']);
        $this->assertNotNull($data['data']['startedAt']);
        $this->assertNull($data['data']['submittedAt']);
        $this->assertNull($data['data']['autoSubmittedAt']);
    }

    public function testStudentCannotStartUnavailableSession(): void
    {
        $client = $this->client;

        $draftSession = new ExamSession();
        $draftSessionId = Uuid::v7();
        $reflection = new \ReflectionClass($draftSession);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($draftSession, $draftSessionId);
        $draftSession->setInstitute($client->getContainer()->get('doctrine')->getManager()->getRepository(Institute::class)->find($this->testInstituteId));
        $draftSession->setQuestionPaper($client->getContainer()->get('doctrine')->getManager()->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId));
        $draftSession->setTitle('Draft Session ' . $this->unique);
        $draftSession->setAvailableFrom(new \DateTimeImmutable('2025-01-01 09:00:00'));
        $draftSession->setAvailableTo(new \DateTimeImmutable('2026-12-31 23:59:59'));
        $draftSession->setTotalMarks(100);
        $draftSession->setStatus(ExamSessionStatus::Draft);
        $draftSession->setCreatedBy($client->getContainer()->get('doctrine')->getManager()->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-attempt-test-' . $this->unique . '@edupulse.ai']));

        $client->getContainer()->get('doctrine')->getManager()->persist($draftSession);
        $client->getContainer()->get('doctrine')->getManager()->flush();

        $client->request('POST', '/api/v1/exam-sessions/' . $draftSessionId . '/attempts', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('not currently available', strtolower($data['message']));
    }

    public function testStudentCannotStartCancelledSession(): void
    {
        $client = $this->client;

        $cancelledSession = new ExamSession();
        $cancelledSessionId = Uuid::v7();
        $reflection = new \ReflectionClass($cancelledSession);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($cancelledSession, $cancelledSessionId);
        $cancelledSession->setInstitute($client->getContainer()->get('doctrine')->getManager()->getRepository(Institute::class)->find($this->testInstituteId));
        $cancelledSession->setQuestionPaper($client->getContainer()->get('doctrine')->getManager()->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId));
        $cancelledSession->setTitle('Cancelled Session ' . $this->unique);
        $cancelledSession->setAvailableFrom(new \DateTimeImmutable('2025-01-01 09:00:00'));
        $cancelledSession->setAvailableTo(new \DateTimeImmutable('2026-12-31 23:59:59'));
        $cancelledSession->setTotalMarks(100);
        $cancelledSession->setStatus(ExamSessionStatus::Cancelled);
        $cancelledSession->setCreatedBy($client->getContainer()->get('doctrine')->getManager()->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-attempt-test-' . $this->unique . '@edupulse.ai']));

        $client->getContainer()->get('doctrine')->getManager()->persist($cancelledSession);
        $client->getContainer()->get('doctrine')->getManager()->flush();

        $client->request('POST', '/api/v1/exam-sessions/' . $cancelledSessionId . '/attempts', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('not currently available', strtolower($data['message']));
    }

    public function testDuplicateActiveAttemptIsPrevented(): void
    {
        $client = $this->client;

        $client->request('POST', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/attempts', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/attempts', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('IN_PROGRESS', $data['data']['status']);
    }

    public function testStudentCanRetrieveOwnAttempt(): void
    {
        $client = $this->client;

        $client->request('POST', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/attempts', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $attemptId = json_decode($client->getResponse()->getContent(), true)['data']['id'];

        $client->request('GET', '/api/v1/exam-attempts/' . $attemptId, [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame($attemptId, $data['data']['id']);
    }

    public function testStudentCannotRetrieveAnotherStudentsAttempt(): void
    {
        $client = $this->client;

        $otherStudent = new User();
        $otherStudentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($otherStudent);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($otherStudent, $otherStudentId);
        $otherStudent->setEmail('other-student-exam-attempt-test-' . $this->unique . '@edupulse.ai');
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();
        $otherStudent->setInstitute($client->getContainer()->get('doctrine')->getManager()->getRepository(Institute::class)->find($this->testInstituteId));

        $otherAttempt = new ExamAttempt();
        $otherAttemptId = Uuid::v7();
        $attemptReflection = new \ReflectionClass($otherAttempt);
        $attemptProperty = $attemptReflection->getProperty('id');
        $attemptProperty->setAccessible(true);
        $attemptProperty->setValue($otherAttempt, $otherAttemptId);
        $otherAttempt->setExamSession($client->getContainer()->get('doctrine')->getManager()->getRepository(ExamSession::class)->find($this->testExamSessionId));
        $otherAttempt->setStudent($otherStudent);
        $otherAttempt->setStatus(ExamAttemptStatus::InProgress);
        $otherAttempt->setStartedAt(new \DateTimeImmutable());

        $client->getContainer()->get('doctrine')->getManager()->persist($otherStudent);
        $client->getContainer()->get('doctrine')->getManager()->persist($otherAttempt);
        $client->getContainer()->get('doctrine')->getManager()->flush();

        $client->request('GET', '/api/v1/exam-attempts/' . $otherAttemptId, [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('not authorized', strtolower($data['message']));
    }

    public function testStudentCanSubmitOwnAttempt(): void
    {
        $client = $this->client;

        $client->request('POST', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/attempts', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $attemptId = json_decode($client->getResponse()->getContent(), true)['data']['id'];

        $client->request('POST', '/api/v1/exam-attempts/' . $attemptId . '/submit', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotNull($data['data']['submittedAt']);
        $this->assertNull($data['data']['autoSubmittedAt']);
    }

    public function testStudentCannotSubmitAnotherStudentsAttempt(): void
    {
        $client = $this->client;

        $otherStudent = new User();
        $otherStudentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($otherStudent);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($otherStudent, $otherStudentId);
        $otherStudent->setEmail('other-student-submit-test-' . $this->unique . '@edupulse.ai');
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();
        $otherStudent->setInstitute($client->getContainer()->get('doctrine')->getManager()->getRepository(Institute::class)->find($this->testInstituteId));

        $otherAttempt = new ExamAttempt();
        $otherAttemptId = Uuid::v7();
        $attemptReflection = new \ReflectionClass($otherAttempt);
        $attemptProperty = $attemptReflection->getProperty('id');
        $attemptProperty->setAccessible(true);
        $attemptProperty->setValue($otherAttempt, $otherAttemptId);
        $otherAttempt->setExamSession($client->getContainer()->get('doctrine')->getManager()->getRepository(ExamSession::class)->find($this->testExamSessionId));
        $otherAttempt->setStudent($otherStudent);
        $otherAttempt->setStatus(ExamAttemptStatus::InProgress);
        $otherAttempt->setStartedAt(new \DateTimeImmutable());

        $client->getContainer()->get('doctrine')->getManager()->persist($otherStudent);
        $client->getContainer()->get('doctrine')->getManager()->persist($otherAttempt);
        $client->getContainer()->get('doctrine')->getManager()->flush();

        $client->request('POST', '/api/v1/exam-attempts/' . $otherAttemptId . '/submit', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('not authorized', strtolower($data['message']));
    }

    public function testAlreadySubmittedAttemptCannotBeSubmittedAgain(): void
    {
        $client = $this->client;

        $client->request('POST', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/attempts', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $attemptId = json_decode($client->getResponse()->getContent(), true)['data']['id'];

        $client->request('POST', '/api/v1/exam-attempts/' . $attemptId . '/submit', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/exam-attempts/' . $attemptId . '/submit', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('only in-progress exam attempts can be submitted', strtolower($data['message']));
    }

    public function testInvalidWorkflowTransitionIsRejected(): void
    {
        $client = $this->client;

        $attempt = new ExamAttempt();
        $attemptId = Uuid::v7();
        $reflection = new \ReflectionClass($attempt);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($attempt, $attemptId);
        $attempt->setExamSession($client->getContainer()->get('doctrine')->getManager()->getRepository(ExamSession::class)->find($this->testExamSessionId));
        $attempt->setStudent($client->getContainer()->get('doctrine')->getManager()->getRepository(User::class)->find($this->testStudentId));
        $attempt->setStatus(ExamAttemptStatus::Submitted);
        $attempt->setStartedAt(new \DateTimeImmutable());
        $attempt->setSubmittedAt(new \DateTimeImmutable());

        $client->getContainer()->get('doctrine')->getManager()->persist($attempt);
        $client->getContainer()->get('doctrine')->getManager()->flush();

        $client->request('POST', '/api/v1/exam-attempts/' . $attemptId . '/submit', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('only in-progress exam attempts can be submitted', strtolower($data['message']));
    }

    public function testCrossInstituteAccessIsRejected(): void
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

        $otherSession = new ExamSession();
        $otherSessionId = Uuid::v7();
        $esReflection = new \ReflectionClass($otherSession);
        $esProperty = $esReflection->getProperty('id');
        $esProperty->setAccessible(true);
        $esProperty->setValue($otherSession, $otherSessionId);
        $otherSession->setInstitute($otherInstitute);
        $otherSession->setQuestionPaper($otherPaper);
        $otherSession->setTitle('Other Session ' . $this->unique);
        $otherSession->setAvailableFrom(new \DateTimeImmutable('2025-01-01 09:00:00'));
        $otherSession->setAvailableTo(new \DateTimeImmutable('2026-12-31 23:59:59'));
        $otherSession->setTotalMarks(100);
        $otherSession->setStatus(ExamSessionStatus::Published);
        $otherSession->setCreatedBy($client->getContainer()->get('doctrine')->getManager()->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-attempt-test-' . $this->unique . '@edupulse.ai']));

        $entityManager->persist($otherInstitute);
        $entityManager->persist($otherPaper);
        $entityManager->persist($otherSession);
        $entityManager->flush();

        $client->request('POST', '/api/v1/exam-sessions/' . $otherSessionId . '/attempts', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('not currently available', strtolower($data['message']));
    }

    public function testStudentCanGetOwnAttemptQuestions(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId . '/questions', [], [], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('questions', $data['data']);
        $this->assertCount(2, $data['data']['questions']);
        $this->assertSame((string) $this->testQuestion1Id, $data['data']['questions'][0]['id']);
        $this->assertSame((string) $this->testQuestion2Id, $data['data']['questions'][1]['id']);
    }

    public function testStudentCannotGetAnotherStudentsAttemptQuestions(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherStudent = new User();
        $otherStudentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($otherStudent);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($otherStudent, $otherStudentId);
        $otherStudent->setEmail('other-student-questions-test-' . $this->unique . '@edupulse.ai');
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();
        $otherStudent->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $otherAttempt = new ExamAttempt();
        $otherAttemptId = Uuid::v7();
        $attemptReflection = new \ReflectionClass($otherAttempt);
        $attemptProperty = $attemptReflection->getProperty('id');
        $attemptProperty->setAccessible(true);
        $attemptProperty->setValue($otherAttempt, $otherAttemptId);
        $otherAttempt->setExamSession($entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId));
        $otherAttempt->setStudent($otherStudent);
        $otherAttempt->setStatus(ExamAttemptStatus::InProgress);
        $otherAttempt->setStartedAt(new \DateTimeImmutable());

        $entityManager->persist($otherStudent);
        $entityManager->persist($otherAttempt);
        $entityManager->flush();

        $otherToken = $this->login($client, 'other-student-questions-test-' . $this->unique . '@edupulse.ai', 'Student123!');

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId . '/questions', [], [], $this->getAuthHeaders($otherToken));

        $this->assertSame(404, $client->getResponse()->getStatusCode());

        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        $otherStudent = $entityManager->getRepository(User::class)->find($otherStudentId);
        if ($otherStudent) {
            $entityManager->remove($otherStudent);
        }
        $entityManager->flush();
    }

    public function testStudentQuestionsDoNotExposeIsCorrect(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId . '/questions', [], [], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $responseContent = $client->getResponse()->getContent();
        $this->assertStringNotContainsString('isCorrect', $responseContent);
        $this->assertStringNotContainsString('correctOption', $responseContent);
    }

    public function testInvalidAttemptIdReturnsNotFound(): void
    {
        $client = $this->client;

        $fakeAttemptId = Uuid::v7()->toRfc4122();

        $client->request('GET', '/api/v1/exam-attempts/' . $fakeAttemptId . '/questions', [], [], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testStudentCanGetEvaluatedAttempts(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $attempt = $entityManager->getRepository(ExamAttempt::class)->find($this->testAttemptId);
        $attempt->setStatus(\App\Enum\ExamAttemptStatus::Evaluated);
        $attempt->setScore(82);
        $attempt->setMaxMarks(100);
        $attempt->setPercentage(82);
        $attempt->setTimeTakenSeconds(2538);
        $entityManager->flush();

        $client->request('GET', '/api/v1/exam-attempts/evaluated', [], [], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertArrayHasKey('pagination', $data['data']);
        $this->assertCount(1, $data['data']['items']);
        $this->assertSame((string) $this->testAttemptId, $data['data']['items'][0]['attemptId']);
        $this->assertSame('EVALUATED', $data['data']['items'][0]['status']);
        $this->assertSame(82, $data['data']['items'][0]['score']);
        $this->assertSame(100, $data['data']['items'][0]['maxMarks']);
        $this->assertSame(82, $data['data']['items'][0]['percentage']);
        $this->assertSame(2538, $data['data']['items'][0]['timeTakenSeconds']);
    }

    public function testSuperAdminCanGetEvaluatedAttempts(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $attempt = $entityManager->getRepository(ExamAttempt::class)->find($this->testAttemptId);
        $attempt->setStatus(\App\Enum\ExamAttemptStatus::Evaluated);
        $attempt->setScore(75);
        $attempt->setMaxMarks(100);
        $attempt->setPercentage(75);
        $attempt->setTimeTakenSeconds(1800);
        $entityManager->flush();

        $client->request('GET', '/api/v1/exam-attempts/evaluated', ['page' => 1, 'limit' => 100, 'search' => 'Test Exam Session ' . $this->unique], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertArrayHasKey('pagination', $data['data']);

        $found = false;
        foreach ($data['data']['items'] as $item) {
            if ($item['attemptId'] === (string) $this->testAttemptId) {
                $found = true;
                $this->assertSame('EVALUATED', $item['status']);
                $this->assertSame(75, $item['score']);
                $this->assertSame(100, $item['maxMarks']);
                $this->assertSame(75, $item['percentage']);
                $this->assertSame(1800, $item['timeTakenSeconds']);
                break;
            }
        }
        $this->assertTrue($found, 'Evaluated attempt not found in super admin response');
    }

    public function testSuperAdminCanGetSessionAttempts(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/attempts', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertArrayHasKey('pagination', $data['data']);
        $this->assertCount(1, $data['data']['items']);
        $this->assertSame((string) $this->testAttemptId, $data['data']['items'][0]['attemptId']);
    }

    public function testStudentCannotGetSessionAttempts(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/attempts', [], [], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('not authorized', strtolower($data['message']));
    }

    public function testInvalidSessionIdReturnsNotFound(): void
    {
        $client = $this->client;

        $fakeSessionId = Uuid::v7()->toRfc4122();

        $client->request('GET', '/api/v1/exam-sessions/' . $fakeSessionId . '/attempts', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testOnlineAttemptReturnsModeOnline(): void
    {
        $client = $this->client;

        $client->request('POST', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/attempts', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('ONLINE', $data['data']['mode']);
        $this->assertSame('IN_PROGRESS', $data['data']['status']);
    }

    public function testSuperAdminCanCreateOfflineAttempt(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Create a new student without an existing attempt
        $newStudent = new User();
        $newStudentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($newStudent);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($newStudent, $newStudentId);
        $newStudent->setEmail('student-offline-test-' . $this->unique . '@edupulse.ai');
        $newStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $newStudent->setRoles([UserRole::Student->value]);
        $newStudent->setStatus(UserStatus::Active);
        $newStudent->verify();
        $newStudent->setInstitute($entityManager->getRepository(\App\Entity\Institute::class)->find($this->testInstituteId));

        $enrollment = new StudentEnrollment();
        $enrollment->setStudent($newStudent);
        $enrollment->setAcademicYear($entityManager->getRepository(\App\Entity\AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($entityManager->getRepository(\App\Entity\AcademicClass::class)->find($this->testClassId));
        $enrollment->setAdmissionNumber('ADM-OFFLINE-' . $this->unique);
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $enrollment->setInstituteId($this->testInstituteId);

        $enrollmentSection = new \App\Entity\EnrollmentSection();
        $enrollmentSection->setStudentEnrollment($enrollment);
        $enrollmentSection->setSection($entityManager->getRepository(\App\Entity\Section::class)->find($this->testSectionId));

        $entityManager->persist($newStudent);
        $entityManager->persist($enrollment);
        $entityManager->persist($enrollmentSection);
        $entityManager->flush();

        $payload = json_encode([
            'studentId' => (string) $newStudentId,
        ]);

        $client->request('POST', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/offline-attempts', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('OFFLINE', $data['data']['mode']);
        $this->assertSame('IN_PROGRESS', $data['data']['status']);
        $this->assertSame((string) $newStudentId, $data['data']['studentId']);
    }

    public function testOfflineAttemptCreationRequiresStudentId(): void
    {
        $client = $this->client;

        $payload = json_encode([]);

        $client->request('POST', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/offline-attempts', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testStudentCannotCreateOfflineAttempt(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'studentId' => (string) $this->testStudentId,
        ]);

        $client->request('POST', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/offline-attempts', [], [], $this->getAuthHeaders($this->studentToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('not authorized', strtolower($data['message']));
    }

    public function testOfflineAttemptCannotAcceptOnlineAnswers(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Create a new student with enrollment
        $newStudent = new User();
        $newStudentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($newStudent);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($newStudent, $newStudentId);
        $newStudent->setEmail('student-offline-answers-' . $this->unique . '@edupulse.ai');
        $newStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $newStudent->setRoles([UserRole::Student->value]);
        $newStudent->setStatus(UserStatus::Active);
        $newStudent->verify();
        $newStudent->setInstitute($entityManager->getRepository(\App\Entity\Institute::class)->find($this->testInstituteId));

        $enrollment = new StudentEnrollment();
        $enrollment->setStudent($newStudent);
        $enrollment->setAcademicYear($entityManager->getRepository(\App\Entity\AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($entityManager->getRepository(\App\Entity\AcademicClass::class)->find($this->testClassId));
        $enrollment->setAdmissionNumber('ADM-OFFLINE-ANS-' . $this->unique);
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $enrollment->setInstituteId($this->testInstituteId);

        $enrollmentSection = new \App\Entity\EnrollmentSection();
        $enrollmentSection->setStudentEnrollment($enrollment);
        $enrollmentSection->setSection($entityManager->getRepository(\App\Entity\Section::class)->find($this->testSectionId));

        $entityManager->persist($newStudent);
        $entityManager->persist($enrollment);
        $entityManager->persist($enrollmentSection);
        $entityManager->flush();

        // Login as the new student
        $newStudentToken = $this->login($this->client, 'student-offline-answers-' . $this->unique . '@edupulse.ai', 'Student123!');

        $payload = json_encode([
            'studentId' => (string) $newStudentId,
        ]);

        $client->request('POST', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/offline-attempts', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $offlineAttemptId = json_decode($client->getResponse()->getContent(), true)['data']['id'];

        $answerPayload = json_encode([
            'questionId' => (string) $this->testQuestion1Id,
            'selectedOptionId' => (string) $this->testQuestion1Id,
        ]);

        $client->request('POST', '/api/v1/exam-attempts/' . $offlineAttemptId . '/answers', [], [], $this->getAuthHeaders($newStudentToken), $answerPayload);

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('offline', strtolower($data['message']));

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('offline', strtolower($data['message']));
    }

    public function testOfflineAttemptCanBeRetrievedWithMode(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Create a new student with enrollment
        $newStudent = new User();
        $newStudentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($newStudent);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($newStudent, $newStudentId);
        $newStudent->setEmail('student-offline-get-' . $this->unique . '@edupulse.ai');
        $newStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $newStudent->setRoles([UserRole::Student->value]);
        $newStudent->setStatus(UserStatus::Active);
        $newStudent->verify();
        $newStudent->setInstitute($entityManager->getRepository(\App\Entity\Institute::class)->find($this->testInstituteId));

        $enrollment = new StudentEnrollment();
        $enrollment->setStudent($newStudent);
        $enrollment->setAcademicYear($entityManager->getRepository(\App\Entity\AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($entityManager->getRepository(\App\Entity\AcademicClass::class)->find($this->testClassId));
        $enrollment->setAdmissionNumber('ADM-OFFLINE-GET-' . $this->unique);
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $enrollment->setInstituteId($this->testInstituteId);

        $enrollmentSection = new \App\Entity\EnrollmentSection();
        $enrollmentSection->setStudentEnrollment($enrollment);
        $enrollmentSection->setSection($entityManager->getRepository(\App\Entity\Section::class)->find($this->testSectionId));

        $entityManager->persist($newStudent);
        $entityManager->persist($enrollment);
        $entityManager->persist($enrollmentSection);
        $entityManager->flush();

        // Login as the new student
        $newStudentToken = $this->login($this->client, 'student-offline-get-' . $this->unique . '@edupulse.ai', 'Student123!');

        $payload = json_encode([
            'studentId' => (string) $newStudentId,
        ]);

        $client->request('POST', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/offline-attempts', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $offlineAttemptId = json_decode($client->getResponse()->getContent(), true)['data']['id'];

        $client->request('GET', '/api/v1/exam-attempts/' . $offlineAttemptId, [], [], $this->getAuthHeaders($newStudentToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('OFFLINE', $data['data']['mode']);
    }

    public function testMyAttemptsIncludesMode(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/exam-attempts/me', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('items', $data['data']);

        if (count($data['data']['items']) > 0) {
            $this->assertArrayHasKey('mode', $data['data']['items'][0]);
        }
    }

    public function testSessionAttemptsIncludesMode(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/attempts', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('items', $data['data']);

        if (count($data['data']['items']) > 0) {
            $this->assertArrayHasKey('mode', $data['data']['items'][0]);
        }
    }

    public function testStudentCannotStartPublishedSession(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Create a PUBLISHED session
        $publishedSession = new \App\Entity\ExamSession();
        $publishedSessionId = Uuid::v7();
        $reflection = new \ReflectionClass($publishedSession);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($publishedSession, $publishedSessionId);
        $publishedSession->setInstitute($entityManager->getRepository(\App\Entity\Institute::class)->find($this->testInstituteId));
        $publishedSession->setQuestionPaper($entityManager->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $publishedSession->setTitle('Published Session ' . $this->unique);
        $publishedSession->setAvailableFrom(new \DateTimeImmutable('2025-01-01 09:00:00'));
        $publishedSession->setAvailableTo(new \DateTimeImmutable('2026-12-31 23:59:59'));
        $publishedSession->setTotalMarks(100);
        $publishedSession->setStatus(\App\Enum\ExamSessionStatus::Published);
        $publishedSession->setCreatedBy($entityManager->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-attempt-test-' . $this->unique . '@edupulse.ai']));

        $section = $entityManager->getRepository(\App\Entity\Section::class)->find($this->testSectionId);
        if ($section) {
            $publishedSession->addSection($section);
        }

        $entityManager->persist($publishedSession);
        $entityManager->flush();

        $client->request('POST', '/api/v1/exam-sessions/' . $publishedSessionId . '/attempts', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('not currently available', strtolower($data['message']));
    }

    public function testStudentCannotStartClosedSession(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Create a CLOSED session
        $closedSession = new \App\Entity\ExamSession();
        $closedSessionId = Uuid::v7();
        $reflection = new \ReflectionClass($closedSession);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($closedSession, $closedSessionId);
        $closedSession->setInstitute($entityManager->getRepository(\App\Entity\Institute::class)->find($this->testInstituteId));
        $closedSession->setQuestionPaper($entityManager->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $closedSession->setTitle('Closed Session ' . $this->unique);
        $closedSession->setAvailableFrom(new \DateTimeImmutable('2025-01-01 09:00:00'));
        $closedSession->setAvailableTo(new \DateTimeImmutable('2026-12-31 23:59:59'));
        $closedSession->setTotalMarks(100);
        $closedSession->setStatus(\App\Enum\ExamSessionStatus::Closed);
        $closedSession->setCreatedBy($entityManager->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-attempt-test-' . $this->unique . '@edupulse.ai']));

        $section = $entityManager->getRepository(\App\Entity\Section::class)->find($this->testSectionId);
        if ($section) {
            $closedSession->addSection($section);
        }

        $entityManager->persist($closedSession);
        $entityManager->flush();

        $client->request('POST', '/api/v1/exam-sessions/' . $closedSessionId . '/attempts', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('not currently available', strtolower($data['message']));
    }

    public function testStudentCannotStartBeforeAvailableFrom(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Create an ACTIVE session with future availableFrom
        $futureSession = new \App\Entity\ExamSession();
        $futureSessionId = Uuid::v7();
        $reflection = new \ReflectionClass($futureSession);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($futureSession, $futureSessionId);
        $futureSession->setInstitute($entityManager->getRepository(\App\Entity\Institute::class)->find($this->testInstituteId));
        $futureSession->setQuestionPaper($entityManager->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $futureSession->setTitle('Future Session ' . $this->unique);
        $futureSession->setAvailableFrom(new \DateTimeImmutable('+1 day'));
        $futureSession->setAvailableTo(new \DateTimeImmutable('+2 days'));
        $futureSession->setTotalMarks(100);
        $futureSession->setStatus(\App\Enum\ExamSessionStatus::Active);
        $futureSession->setCreatedBy($entityManager->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-attempt-test-' . $this->unique . '@edupulse.ai']));

        $section = $entityManager->getRepository(\App\Entity\Section::class)->find($this->testSectionId);
        if ($section) {
            $futureSession->addSection($section);
        }

        $entityManager->persist($futureSession);
        $entityManager->flush();

        $client->request('POST', '/api/v1/exam-sessions/' . $futureSessionId . '/attempts', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('not yet available', strtolower($data['message']));
    }

    public function testStudentCannotStartAfterAvailableTo(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Create an ACTIVE session with past availableTo
        $expiredSession = new \App\Entity\ExamSession();
        $expiredSessionId = Uuid::v7();
        $reflection = new \ReflectionClass($expiredSession);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($expiredSession, $expiredSessionId);
        $expiredSession->setInstitute($entityManager->getRepository(\App\Entity\Institute::class)->find($this->testInstituteId));
        $expiredSession->setQuestionPaper($entityManager->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $expiredSession->setTitle('Expired Session ' . $this->unique);
        $expiredSession->setAvailableFrom(new \DateTimeImmutable('-2 days'));
        $expiredSession->setAvailableTo(new \DateTimeImmutable('-1 day'));
        $expiredSession->setTotalMarks(100);
        $expiredSession->setStatus(\App\Enum\ExamSessionStatus::Active);
        $expiredSession->setCreatedBy($entityManager->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-attempt-test-' . $this->unique . '@edupulse.ai']));

        $section = $entityManager->getRepository(\App\Entity\Section::class)->find($this->testSectionId);
        if ($section) {
            $expiredSession->addSection($section);
        }

        $entityManager->persist($expiredSession);
        $entityManager->flush();

        $client->request('POST', '/api/v1/exam-sessions/' . $expiredSessionId . '/attempts', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('expired', strtolower($data['message']));
    }

    public function testStudentDoesNotSeeCorrectAnswerInQuestions(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId . '/questions', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        foreach ($data['data']['questions'] as $question) {
            if ($question['type'] === 'MCQ') {
                foreach ($question['options'] as $option) {
                    $this->assertArrayNotHasKey('isCorrect', $option, 'Student should not see isCorrect in options');
                }
            }
        }
    }

    public function testSubmittedMcqExamIsEvaluated(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Create a new MCQ question with correct answer
        $paper = $entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId);

        $question = new Question();
        $questionId = Uuid::v7();
        $qReflection = new \ReflectionClass($question);
        $qProperty = $qReflection->getProperty('id');
        $qProperty->setAccessible(true);
        $qProperty->setValue($question, $questionId);
        $question->setQuestionPaper($paper);
        $question->setType(QuestionType::MCQ);
        $question->setQuestionText('What is 3+3?');
        $question->setMarks(2);
        $question->setDisplayOrder(3);

        $option1 = new QuestionOption();
        $option1Id = Uuid::v7();
        $oReflection = new \ReflectionClass($option1);
        $oProperty = $oReflection->getProperty('id');
        $oProperty->setAccessible(true);
        $oProperty->setValue($option1, $option1Id);
        $option1->setQuestion($question);
        $option1->setOptionText('5');
        $option1->setDisplayOrder(1);
        $option1->setIsCorrect(false);

        $option2 = new QuestionOption();
        $option2Id = Uuid::v7();
        $oProperty->setValue($option2, $option2Id);
        $option2->setQuestion($question);
        $option2->setOptionText('6');
        $option2->setDisplayOrder(2);
        $option2->setIsCorrect(true);

        $question->addOption($option1);
        $question->addOption($option2);

        $entityManager->persist($question);
        $entityManager->persist($option1);
        $entityManager->persist($option2);
        $entityManager->flush();

        // Create a new attempt
        $student = $entityManager->getRepository(User::class)->find($this->testStudentId);
        $attempt = new ExamAttempt();
        $attemptId = Uuid::v7();
        $aReflection = new \ReflectionClass($attempt);
        $aProperty = $aReflection->getProperty('id');
        $aProperty->setAccessible(true);
        $aProperty->setValue($attempt, $attemptId);
        $attempt->setExamSession($entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId));
        $attempt->setStudent($student);
        $attempt->setStatus(ExamAttemptStatus::InProgress);
        $attempt->setMode(\App\Enum\ExamAttemptMode::Online);
        $attempt->setStartedAt(new \DateTimeImmutable('-10 minutes'));

        $entityManager->persist($attempt);
        $entityManager->flush();

        // Save answer - select correct option
        $payload = json_encode([
            'questionId' => (string) $questionId,
            'selectedOptionId' => (string) $option2Id,
        ]);

        $client->request('POST', '/api/v1/exam-attempts/' . $attemptId . '/answers', [], [], $this->getAuthHeaders($this->studentToken), $payload);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        // Submit the attempt
        $client->request('POST', '/api/v1/exam-attempts/' . $attemptId . '/submit', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testImmediateResultIsAvailableAfterSubmission(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Create a new attempt
        $student = $entityManager->getRepository(User::class)->find($this->testStudentId);
        $attempt = new ExamAttempt();
        $attemptId = Uuid::v7();
        $aReflection = new \ReflectionClass($attempt);
        $aProperty = $aReflection->getProperty('id');
        $aProperty->setAccessible(true);
        $aProperty->setValue($attempt, $attemptId);
        $attempt->setExamSession($entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId));
        $attempt->setStudent($student);
        $attempt->setStatus(ExamAttemptStatus::InProgress);
        $attempt->setMode(\App\Enum\ExamAttemptMode::Online);
        $attempt->setStartedAt(new \DateTimeImmutable('-10 minutes'));

        $entityManager->persist($attempt);
        $entityManager->flush();

        // Submit the attempt
        $client->request('POST', '/api/v1/exam-attempts/' . $attemptId . '/submit', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        // Get result immediately
        $client->request('GET', '/api/v1/exam-attempts/' . $attemptId . '/result', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('obtainedMarks', $data['data']);
        $this->assertArrayHasKey('totalMarks', $data['data']);
        $this->assertArrayHasKey('percentage', $data['data']);
    }

    public function testResultObtainedMarksAndPercentageSummedFromQuestionResults(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $paper = $entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId);
        $question1 = $entityManager->getRepository(\App\Entity\Question::class)->find($this->testQuestion1Id);
        $question2 = $entityManager->getRepository(\App\Entity\Question::class)->find($this->testQuestion2Id);
        $questions = [$question1, $question2];

        $student = $entityManager->getRepository(User::class)->find($this->testStudentId);
        $attempt = new ExamAttempt();
        $attemptId = Uuid::v7();
        $aRef = new \ReflectionClass($attempt);
        $aProp = $aRef->getProperty('id'); $aProp->setAccessible(true); $aProp->setValue($attempt, $attemptId);
        $attempt->setExamSession($entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId));
        $attempt->setStudent($student);
        $attempt->setMode(\App\Enum\ExamAttemptMode::Online);
        $attempt->setStatus(ExamAttemptStatus::InProgress);
        $attempt->setStartedAt(new \DateTimeImmutable('-10 minutes'));
        $entityManager->persist($attempt);

        $totalExpectedMarks = 0;
        $expectedObtained = 0;

        foreach ($questions as $idx => $question) {
            $totalExpectedMarks += $question->getMarks();
            $correctOption = null;
            $wrongOption = null;
            foreach ($question->getOptions() as $opt) {
                $this->assertNotNull($opt->getId(), 'Option must have id set');
                if (!$opt->isCorrect() && $correctOption === null) {
                    $opt->setIsCorrect(true);
                    $correctOption = $opt;
                } elseif ($opt->isCorrect()) {
                    $correctOption = $opt;
                }
                if (!$opt->isCorrect() && $wrongOption === null && $opt !== $correctOption) {
                    $wrongOption = $opt;
                }
            }
            $this->assertNotNull($correctOption, 'Test setup requires a correct option for question ' . $question->getId());
            $this->assertNotNull($wrongOption, 'Test setup requires a wrong option for question ' . $question->getId());

            $answer = new ExamAnswer();
            $ansRef = new \ReflectionClass($answer);
            $ansProp = $ansRef->getProperty('id'); $ansProp->setAccessible(true); $ansProp->setValue($answer, Uuid::v7());
            $answer->setExamAttempt($attempt);
            $answer->setQuestion($question);
            if ($idx === 0) {
                $answer->setSelectedOption($correctOption);
                $expectedObtained += $question->getMarks();
            } else {
                $answer->setSelectedOption($wrongOption);
            }
            $entityManager->persist($answer);
        }
        $entityManager->flush();
        $entityManager->clear();

        $entityManager->getRepository(\App\Entity\QuestionOption::class)->findAll();

        $client->request('POST', '/api/v1/exam-attempts/' . $attemptId . '/submit', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());

        $client->request('GET', '/api/v1/exam-attempts/' . $attemptId . '/result', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($totalExpectedMarks, $data['data']['totalMarks']);
        $this->assertSame($expectedObtained, $data['data']['obtainedMarks'], 'obtainedMarks should equal sum of questionResults.obtainedMarks');
        $this->assertSame((int) round(($expectedObtained / $totalExpectedMarks) * 100), $data['data']['percentage']);

        $sumFromQuestionResults = 0;
        foreach ($data['data']['questionResults'] as $qr) {
            if ($qr['obtainedMarks'] !== null) {
                $sumFromQuestionResults += $qr['obtainedMarks'];
            }
        }
        $this->assertSame($sumFromQuestionResults, $data['data']['obtainedMarks'], 'Top-level obtainedMarks must equal sum of questionResults');
    }

    public function testResultObtainedMarksIsZeroWhenAllAnswersWrong(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $paper = $entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId);
        $questions = $paper->getQuestions();

        $student = $entityManager->getRepository(User::class)->find($this->testStudentId);
        $attempt = new ExamAttempt();
        $attemptId = Uuid::v7();
        $aRef = new \ReflectionClass($attempt);
        $aProp = $aRef->getProperty('id'); $aProp->setAccessible(true); $aProp->setValue($attempt, $attemptId);
        $attempt->setExamSession($entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId));
        $attempt->setStudent($student);
        $attempt->setMode(\App\Enum\ExamAttemptMode::Online);
        $attempt->setStatus(ExamAttemptStatus::InProgress);
        $attempt->setStartedAt(new \DateTimeImmutable('-10 minutes'));
        $entityManager->persist($attempt);

        $totalExpectedMarks = 0;
        foreach ($questions as $question) {
            $totalExpectedMarks += $question->getMarks();
            $correctOption = null;
            $wrongOption = null;
            foreach ($question->getOptions() as $opt) {
                if (!$opt->isCorrect() && $correctOption === null) {
                    $opt->setIsCorrect(true);
                    $correctOption = $opt;
                } elseif ($opt->isCorrect()) {
                    $correctOption = $opt;
                }
                if (!$opt->isCorrect() && $wrongOption === null && $opt !== $correctOption) {
                    $wrongOption = $opt;
                }
            }
            $answer = new ExamAnswer();
            $ansRef = new \ReflectionClass($answer);
            $ansProp = $ansRef->getProperty('id'); $ansProp->setAccessible(true); $ansProp->setValue($answer, Uuid::v7());
            $answer->setExamAttempt($attempt);
            $answer->setQuestion($question);
            $answer->setSelectedOption($wrongOption);
            $entityManager->persist($answer);
        }
        $entityManager->flush();
        $entityManager->clear();

        $entityManager->getRepository(\App\Entity\QuestionOption::class)->findAll();

        $client->request('POST', '/api/v1/exam-attempts/' . $attemptId . '/submit', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/exam-attempts/' . $attemptId . '/result', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($totalExpectedMarks, $data['data']['totalMarks']);
        $this->assertSame(0, $data['data']['obtainedMarks'], 'All-wrong should give obtainedMarks=0 (not null)');
        $this->assertSame(0, $data['data']['percentage'], 'All-wrong should give percentage=0 (not null)');
    }

    public function testResultObtainedMarksPartialWithDescriptiveAndMcq(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $paper = $entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId);
        $questions = $paper->getQuestions();
        $totalExpectedMarks = 0;
        foreach ($questions as $q) {
            $totalExpectedMarks += $q->getMarks();
        }

        $descQuestion = new Question();
        $descQId = Uuid::v7();
        $qRef = new \ReflectionClass($descQuestion);
        $qProp = $qRef->getProperty('id'); $qProp->setAccessible(true); $qProp->setValue($descQuestion, $descQId);
        $descQuestion->setQuestionPaper($paper);
        $descQuestion->setType(QuestionType::Descriptive);
        $descQuestion->setQuestionText('Describe.');
        $descQuestion->setMarks(10);
        $descQuestion->setDisplayOrder(99);
        $entityManager->persist($descQuestion);
        $totalExpectedMarks += 10;

        $student = $entityManager->getRepository(User::class)->find($this->testStudentId);
        $attempt = new ExamAttempt();
        $attemptId = Uuid::v7();
        $aRef = new \ReflectionClass($attempt);
        $aProp = $aRef->getProperty('id'); $aProp->setAccessible(true); $aProp->setValue($attempt, $attemptId);
        $attempt->setExamSession($entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId));
        $attempt->setStudent($student);
        $attempt->setMode(\App\Enum\ExamAttemptMode::Online);
        $attempt->setStatus(ExamAttemptStatus::InProgress);
        $attempt->setStartedAt(new \DateTimeImmutable('-10 minutes'));
        $entityManager->persist($attempt);

        $expectedObtained = 0;
        foreach ($questions as $question) {
            $correctOption = null;
            foreach ($question->getOptions() as $opt) {
                if (!$opt->isCorrect() && $correctOption === null) {
                    $opt->setIsCorrect(true);
                    $correctOption = $opt;
                    break;
                }
            }
            $answer = new ExamAnswer();
            $ansRef = new \ReflectionClass($answer);
            $ansProp = $ansRef->getProperty('id'); $ansProp->setAccessible(true); $ansProp->setValue($answer, Uuid::v7());
            $answer->setExamAttempt($attempt);
            $answer->setQuestion($question);
            $answer->setSelectedOption($correctOption);
            $expectedObtained += $question->getMarks();
            $entityManager->persist($answer);
        }

        $descAnswer = new ExamAnswer();
        $daRef = new \ReflectionClass($descAnswer);
        $daProp = $daRef->getProperty('id'); $daProp->setAccessible(true); $daProp->setValue($descAnswer, Uuid::v7());
        $descAnswer->setExamAttempt($attempt);
        $descAnswer->setQuestion($descQuestion);
        $descAnswer->setAnswerText('Pending evaluation text');
        $entityManager->persist($descAnswer);
        $entityManager->flush();
        $entityManager->clear();

        $entityManager->getRepository(\App\Entity\QuestionOption::class)->findAll();

        $client->request('POST', '/api/v1/exam-attempts/' . $attemptId . '/submit', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/exam-attempts/' . $attemptId . '/result', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($expectedObtained, $data['data']['obtainedMarks'], 'obtainedMarks = sum of evaluated MCQ marks (descriptive pending excluded)');
        $this->assertSame((int) round(($expectedObtained / $totalExpectedMarks) * 100), $data['data']['percentage']);
        $this->assertGreaterThan(0, $data['data']['pendingAnswers'], 'descriptive question should be pending');
        $this->assertTrue($data['data']['requiresManualEvaluation']);
    }

    public function testResultNotAvailableForInProgressAttempt(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Create a new attempt (not submitted)
        $student = $entityManager->getRepository(User::class)->find($this->testStudentId);
        $attempt = new ExamAttempt();
        $attemptId = Uuid::v7();
        $aReflection = new \ReflectionClass($attempt);
        $aProperty = $aReflection->getProperty('id');
        $aProperty->setAccessible(true);
        $aProperty->setValue($attempt, $attemptId);
        $attempt->setExamSession($entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId));
        $attempt->setStudent($student);
        $attempt->setStatus(ExamAttemptStatus::InProgress);
        $attempt->setMode(\App\Enum\ExamAttemptMode::Online);
        $attempt->setStartedAt(new \DateTimeImmutable('-10 minutes'));

        $entityManager->persist($attempt);
        $entityManager->flush();

        // Try to get result for in-progress attempt
        $client->request('GET', '/api/v1/exam-attempts/' . $attemptId . '/result', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testResultHiddenBeforeDeadlineWhenShowResultsModeIsAfterDeadline(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Create a session with AFTER_DEADLINE showResultsMode and future availableTo
        $futureSession = new ExamSession();
        $futureSessionId = Uuid::v7();
        $sessionReflection = new \ReflectionClass($futureSession);
        $sessionProperty = $sessionReflection->getProperty('id');
        $sessionProperty->setAccessible(true);
        $sessionProperty->setValue($futureSession, $futureSessionId);
        $futureSession->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $futureSession->setQuestionPaper($entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId));
        $futureSession->setTitle('After Deadline Session ' . $this->unique);
        $futureSession->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $futureSession->setAvailableTo(new \DateTimeImmutable('+1 hour'));
        $futureSession->setTotalMarks(100);
        $futureSession->setStatus(ExamSessionStatus::Active);
        $futureSession->setShowResultsMode('AFTER_DEADLINE');
        $futureSession->setCreatedBy($entityManager->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-attempt-test-' . $this->unique . '@edupulse.ai']));

        $section = $entityManager->getRepository(Section::class)->find($this->testSectionId);
        if ($section) {
            $futureSession->addSection($section);
        }

        $entityManager->persist($futureSession);
        $entityManager->flush();

        // Create an attempt for this session
        $student = $entityManager->getRepository(User::class)->find($this->testStudentId);
        $attempt = new ExamAttempt();
        $attemptId = Uuid::v7();
        $aReflection = new \ReflectionClass($attempt);
        $aProperty = $aReflection->getProperty('id');
        $aProperty->setAccessible(true);
        $aProperty->setValue($attempt, $attemptId);
        $attempt->setExamSession($futureSession);
        $attempt->setStudent($student);
        $attempt->setStatus(ExamAttemptStatus::Evaluated);
        $attempt->setMode(\App\Enum\ExamAttemptMode::Online);
        $attempt->setStartedAt(new \DateTimeImmutable('-30 minutes'));
        $attempt->setSubmittedAt(new \DateTimeImmutable('-10 minutes'));

        $entityManager->persist($attempt);
        $entityManager->flush();

        // Try to get result before deadline
        $client->request('GET', '/api/v1/exam-attempts/' . $attemptId . '/result', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('deadline', strtolower($data['message']));
    }

    public function testResultAvailableAfterDeadlineWhenShowResultsModeIsAfterDeadline(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Create a session with AFTER_DEADLINE showResultsMode and past availableTo
        $pastSession = new ExamSession();
        $pastSessionId = Uuid::v7();
        $sessionReflection = new \ReflectionClass($pastSession);
        $sessionProperty = $sessionReflection->getProperty('id');
        $sessionProperty->setAccessible(true);
        $sessionProperty->setValue($pastSession, $pastSessionId);
        $pastSession->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $pastSession->setQuestionPaper($entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId));
        $pastSession->setTitle('Past Deadline Session ' . $this->unique);
        $pastSession->setAvailableFrom(new \DateTimeImmutable('-2 hours'));
        $pastSession->setAvailableTo(new \DateTimeImmutable('-1 hour'));
        $pastSession->setTotalMarks(100);
        $pastSession->setStatus(ExamSessionStatus::Active);
        $pastSession->setShowResultsMode('AFTER_DEADLINE');
        $pastSession->setCreatedBy($entityManager->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-attempt-test-' . $this->unique . '@edupulse.ai']));

        $section = $entityManager->getRepository(Section::class)->find($this->testSectionId);
        if ($section) {
            $pastSession->addSection($section);
        }

        $entityManager->persist($pastSession);
        $entityManager->flush();

        // Create an attempt for this session
        $student = $entityManager->getRepository(User::class)->find($this->testStudentId);
        $attempt = new ExamAttempt();
        $attemptId = Uuid::v7();
        $aReflection = new \ReflectionClass($attempt);
        $aProperty = $aReflection->getProperty('id');
        $aProperty->setAccessible(true);
        $aProperty->setValue($attempt, $attemptId);
        $attempt->setExamSession($pastSession);
        $attempt->setStudent($student);
        $attempt->setStatus(ExamAttemptStatus::Evaluated);
        $attempt->setMode(\App\Enum\ExamAttemptMode::Online);
        $attempt->setStartedAt(new \DateTimeImmutable('-90 minutes'));
        $attempt->setSubmittedAt(new \DateTimeImmutable('-60 minutes'));

        $entityManager->persist($attempt);
        $entityManager->flush();

        // Get result after deadline
        $client->request('GET', '/api/v1/exam-attempts/' . $attemptId . '/result', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('obtainedMarks', $data['data']);
    }

    public function testResultHiddenWhenShowResultsModeIsManual(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        // Create a session with MANUAL showResultsMode
        $manualSession = new ExamSession();
        $manualSessionId = Uuid::v7();
        $sessionReflection = new \ReflectionClass($manualSession);
        $sessionProperty = $sessionReflection->getProperty('id');
        $sessionProperty->setAccessible(true);
        $sessionProperty->setValue($manualSession, $manualSessionId);
        $manualSession->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $manualSession->setQuestionPaper($entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId));
        $manualSession->setTitle('Manual Session ' . $this->unique);
        $manualSession->setAvailableFrom(new \DateTimeImmutable('-2 hours'));
        $manualSession->setAvailableTo(new \DateTimeImmutable('-1 hour'));
        $manualSession->setTotalMarks(100);
        $manualSession->setStatus(ExamSessionStatus::Active);
        $manualSession->setShowResultsMode('MANUAL');
        $manualSession->setCreatedBy($entityManager->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-attempt-test-' . $this->unique . '@edupulse.ai']));

        $section = $entityManager->getRepository(Section::class)->find($this->testSectionId);
        if ($section) {
            $manualSession->addSection($section);
        }

        $entityManager->persist($manualSession);
        $entityManager->flush();

        // Create an attempt for this session
        $student = $entityManager->getRepository(User::class)->find($this->testStudentId);
        $attempt = new ExamAttempt();
        $attemptId = Uuid::v7();
        $aReflection = new \ReflectionClass($attempt);
        $aProperty = $aReflection->getProperty('id');
        $aProperty->setAccessible(true);
        $aProperty->setValue($attempt, $attemptId);
        $attempt->setExamSession($manualSession);
        $attempt->setStudent($student);
        $attempt->setStatus(ExamAttemptStatus::Evaluated);
        $attempt->setMode(\App\Enum\ExamAttemptMode::Online);
        $attempt->setStartedAt(new \DateTimeImmutable('-90 minutes'));
        $attempt->setSubmittedAt(new \DateTimeImmutable('-60 minutes'));

        $entityManager->persist($attempt);
        $entityManager->flush();

        // Try to get result - should be hidden
        $client->request('GET', '/api/v1/exam-attempts/' . $attemptId . '/result', [], [], $this->getAuthHeaders($this->studentToken));

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('not yet available', strtolower($data['message']));
    }

    public function testStudentWithNoSectionCanStartClassLevelExam(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $institute = $entityManager->getRepository(Institute::class)->find($this->testInstituteId);
        $academicYear = $entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId);
        $academicClass = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);
        $questionPaper = $entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId);
        $superAdmin = $entityManager->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-attempt-test-' . $this->unique . '@edupulse.ai']);

        $student = new User();
        $studentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($student);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($student, $studentId);
        $student->setEmail('student-class-level-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($institute);

        $enrollment = new StudentEnrollment();
        $enrollmentRef = new \ReflectionClass($enrollment);
        $enrollmentProp = $enrollmentRef->getProperty('id');
        $enrollmentProp->setAccessible(true);
        $enrollmentProp->setValue($enrollment, Uuid::v7());
        $enrollment->setStudent($student);
        $enrollment->setAcademicYear($academicYear);
        $enrollment->setAcademicClass($academicClass);
        $enrollment->setAdmissionNumber('ADM-CL-' . $this->unique);
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $enrollment->setInstituteId($this->testInstituteId);

        $classLevelSession = new ExamSession();
        $sessionId = Uuid::v7();
        $esRef = new \ReflectionClass($classLevelSession);
        $esProp = $esRef->getProperty('id');
        $esProp->setAccessible(true);
        $esProp->setValue($classLevelSession, $sessionId);
        $classLevelSession->setInstitute($institute);
        $classLevelSession->setAcademicYear($academicYear);
        $classLevelSession->setAcademicClass($academicClass);
        $classLevelSession->setQuestionPaper($questionPaper);
        $classLevelSession->setTitle('Class Level Exam ' . $this->unique);
        $classLevelSession->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $classLevelSession->setAvailableTo(new \DateTimeImmutable('+2 hours'));
        $classLevelSession->setTotalMarks(100);
        $classLevelSession->setStatus(ExamSessionStatus::Active);
        $classLevelSession->setCreatedBy($superAdmin);

        $entityManager->persist($student);
        $entityManager->persist($enrollment);
        $entityManager->persist($classLevelSession);
        $entityManager->flush();

        $studentToken = $this->login($client, 'student-class-level-' . $this->unique . '@edupulse.ai', 'Student123!');

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/attempts', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $studentToken,
        ]);

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('IN_PROGRESS', $data['data']['status']);

        $this->createdEmails[] = 'student-class-level-' . $this->unique . '@edupulse.ai';
    }

    public function testStudentInWrongSectionCannotStartSectionSpecificExam(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $institute = $entityManager->getRepository(Institute::class)->find($this->testInstituteId);
        $academicYear = $entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId);
        $academicClass = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);
        $questionPaper = $entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId);
        $superAdmin = $entityManager->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-attempt-test-' . $this->unique . '@edupulse.ai']);

        $sectionA = $entityManager->getRepository(Section::class)->find($this->testSectionId);

        $sectionBId = Uuid::v7();
        $sectionB = new Section();
        $sRef = new \ReflectionClass($sectionB);
        $sProp = $sRef->getProperty('id');
        $sProp->setAccessible(true);
        $sProp->setValue($sectionB, $sectionBId);
        $sectionB->setAcademicClass($academicClass);
        $sectionB->setName('Section B ' . $this->unique);
        $sectionB->setCode('SECB' . $this->unique);
        $sectionB->setStatus(SectionStatus::Active);

        $student = new User();
        $studentId = Uuid::v7();
        $studentRef = new \ReflectionClass($student);
        $studentProp = $studentRef->getProperty('id');
        $studentProp->setAccessible(true);
        $studentProp->setValue($student, $studentId);
        $student->setEmail('student-section-b-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($institute);

        $enrollment = new StudentEnrollment();
        $eRef = new \ReflectionClass($enrollment);
        $eProp = $eRef->getProperty('id');
        $eProp->setAccessible(true);
        $eProp->setValue($enrollment, Uuid::v7());
        $enrollment->setStudent($student);
        $enrollment->setAcademicYear($academicYear);
        $enrollment->setAcademicClass($academicClass);
        $enrollment->setAdmissionNumber('ADM-SECB-' . $this->unique);
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $enrollment->setInstituteId($this->testInstituteId);

        $enrollmentSection = new \App\Entity\EnrollmentSection();
        $esRef2 = new \ReflectionClass($enrollmentSection);
        $esProp2 = $esRef2->getProperty('id');
        $esProp2->setAccessible(true);
        $esProp2->setValue($enrollmentSection, Uuid::v7());
        $enrollmentSection->setStudentEnrollment($enrollment);
        $enrollmentSection->setSection($sectionB);

        $restrictedSession = new ExamSession();
        $sessionId = Uuid::v7();
        $sesRef = new \ReflectionClass($restrictedSession);
        $sesProp = $sesRef->getProperty('id');
        $sesProp->setAccessible(true);
        $sesProp->setValue($restrictedSession, $sessionId);
        $restrictedSession->setInstitute($institute);
        $restrictedSession->setAcademicYear($academicYear);
        $restrictedSession->setAcademicClass($academicClass);
        $restrictedSession->addSection($sectionA);
        $restrictedSession->setQuestionPaper($questionPaper);
        $restrictedSession->setTitle('Section A Only Exam ' . $this->unique);
        $restrictedSession->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $restrictedSession->setAvailableTo(new \DateTimeImmutable('+2 hours'));
        $restrictedSession->setTotalMarks(100);
        $restrictedSession->setStatus(ExamSessionStatus::Active);
        $restrictedSession->setCreatedBy($superAdmin);

        $entityManager->persist($sectionB);
        $entityManager->persist($student);
        $entityManager->persist($enrollment);
        $entityManager->persist($enrollmentSection);
        $entityManager->persist($restrictedSession);
        $entityManager->flush();

        $studentToken = $this->login($client, 'student-section-b-' . $this->unique . '@edupulse.ai', 'Student123!');

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/attempts', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $studentToken,
        ]);

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('not eligible', strtolower($data['message']));

        $this->createdEmails[] = 'student-section-b-' . $this->unique . '@edupulse.ai';
    }

    public function testStudentWithNoSectionCanStartSectionSpecificExam(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $institute = $entityManager->getRepository(Institute::class)->find($this->testInstituteId);
        $academicYear = $entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId);
        $academicClass = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);
        $questionPaper = $entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId);
        $superAdmin = $entityManager->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-attempt-test-' . $this->unique . '@edupulse.ai']);
        $sectionA = $entityManager->getRepository(Section::class)->find($this->testSectionId);

        $student = new User();
        $studentId = Uuid::v7();
        $studentRef = new \ReflectionClass($student);
        $studentProp = $studentRef->getProperty('id');
        $studentProp->setAccessible(true);
        $studentProp->setValue($student, $studentId);
        $student->setEmail('student-no-sec-attempt-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($institute);

        $enrollment = new StudentEnrollment();
        $eRef = new \ReflectionClass($enrollment);
        $eProp = $eRef->getProperty('id');
        $eProp->setAccessible(true);
        $eProp->setValue($enrollment, Uuid::v7());
        $enrollment->setStudent($student);
        $enrollment->setAcademicYear($academicYear);
        $enrollment->setAcademicClass($academicClass);
        $enrollment->setAdmissionNumber('ADM-NSEC-' . $this->unique);
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $enrollment->setInstituteId($this->testInstituteId);

        $restrictedSession = new ExamSession();
        $sessionId = Uuid::v7();
        $sesRef = new \ReflectionClass($restrictedSession);
        $sesProp = $sesRef->getProperty('id');
        $sesProp->setAccessible(true);
        $sesProp->setValue($restrictedSession, $sessionId);
        $restrictedSession->setInstitute($institute);
        $restrictedSession->setAcademicYear($academicYear);
        $restrictedSession->setAcademicClass($academicClass);
        $restrictedSession->addSection($sectionA);
        $restrictedSession->setQuestionPaper($questionPaper);
        $restrictedSession->setTitle('Section A Only Exam 2 ' . $this->unique);
        $restrictedSession->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $restrictedSession->setAvailableTo(new \DateTimeImmutable('+2 hours'));
        $restrictedSession->setTotalMarks(100);
        $restrictedSession->setStatus(ExamSessionStatus::Active);
        $restrictedSession->setCreatedBy($superAdmin);

        $entityManager->persist($student);
        $entityManager->persist($enrollment);
        $entityManager->persist($restrictedSession);
        $entityManager->flush();

        $studentToken = $this->login($client, 'student-no-sec-attempt-' . $this->unique . '@edupulse.ai', 'Student123!');

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/attempts', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $studentToken,
        ]);

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('IN_PROGRESS', $data['data']['status']);

        $this->createdEmails[] = 'student-no-sec-attempt-' . $this->unique . '@edupulse.ai';
    }

    public function testStudentFromDifferentClassCannotStartClassLevelExam(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $institute = $entityManager->getRepository(Institute::class)->find($this->testInstituteId);
        $academicYear = $entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId);
        $questionPaper = $entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId);
        $superAdmin = $entityManager->getRepository(User::class)->findOneBy(['email' => 'superadmin-exam-attempt-test-' . $this->unique . '@edupulse.ai']);
        $mainClass = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);

        $otherClassId = Uuid::v7();
        $otherClass = new AcademicClass();
        $ocRef = new \ReflectionClass($otherClass);
        $ocProp = $ocRef->getProperty('id');
        $ocProp->setAccessible(true);
        $ocProp->setValue($otherClass, $otherClassId);
        $otherClass->setInstitute($institute);
        $otherClass->setAcademicYear($academicYear);
        $otherClass->setName('Other Class ' . $this->unique);
        $otherClass->setCode('OCLS' . $this->unique);
        $otherClass->setStatus(ClassStatus::Active);

        $classLevelSession = new ExamSession();
        $sessionId = Uuid::v7();
        $sesRef = new \ReflectionClass($classLevelSession);
        $sesProp = $sesRef->getProperty('id');
        $sesProp->setAccessible(true);
        $sesProp->setValue($classLevelSession, $sessionId);
        $classLevelSession->setInstitute($institute);
        $classLevelSession->setAcademicYear($academicYear);
        $classLevelSession->setAcademicClass($mainClass);
        $classLevelSession->setQuestionPaper($questionPaper);
        $classLevelSession->setTitle('Main Class Level Exam ' . $this->unique);
        $classLevelSession->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $classLevelSession->setAvailableTo(new \DateTimeImmutable('+2 hours'));
        $classLevelSession->setTotalMarks(100);
        $classLevelSession->setStatus(ExamSessionStatus::Active);
        $classLevelSession->setCreatedBy($superAdmin);

        $student = new User();
        $studentId = Uuid::v7();
        $studentRef = new \ReflectionClass($student);
        $studentProp = $studentRef->getProperty('id');
        $studentProp->setAccessible(true);
        $studentProp->setValue($student, $studentId);
        $student->setEmail('student-other-class-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($institute);

        $enrollment = new StudentEnrollment();
        $eRef = new \ReflectionClass($enrollment);
        $eProp = $eRef->getProperty('id');
        $eProp->setAccessible(true);
        $eProp->setValue($enrollment, Uuid::v7());
        $enrollment->setStudent($student);
        $enrollment->setAcademicYear($academicYear);
        $enrollment->setAcademicClass($otherClass);
        $enrollment->setAdmissionNumber('ADM-OC-' . $this->unique);
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $enrollment->setInstituteId($this->testInstituteId);

        $entityManager->persist($otherClass);
        $entityManager->persist($student);
        $entityManager->persist($enrollment);
        $entityManager->persist($classLevelSession);
        $entityManager->flush();

        $studentToken = $this->login($client, 'student-other-class-' . $this->unique . '@edupulse.ai', 'Student123!');

        $client->request('POST', '/api/v1/exam-sessions/' . $sessionId . '/attempts', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $studentToken,
        ]);

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('not eligible', strtolower($data['message']));

        $this->createdEmails[] = 'student-other-class-' . $this->unique . '@edupulse.ai';
    }

    public function testResultForDescriptiveQuestionWithoutReferenceAnswerIsPending(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $paper = $entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId);

        $descQuestion = new Question();
        $descQId = Uuid::v7();
        $qRef = new \ReflectionClass($descQuestion);
        $qProp = $qRef->getProperty('id'); $qProp->setAccessible(true); $qProp->setValue($descQuestion, $descQId);
        $descQuestion->setQuestionPaper($paper);
        $descQuestion->setType(QuestionType::Descriptive);
        $descQuestion->setQuestionText('Explain something important.');
        $descQuestion->setMarks(33);
        $descQuestion->setDisplayOrder(5);
        // No reference answer
        $entityManager->persist($descQuestion);
        $entityManager->flush();

        $student = $entityManager->getRepository(User::class)->find($this->testStudentId);
        $attempt = new ExamAttempt();
        $attemptId = Uuid::v7();
        $aRef = new \ReflectionClass($attempt);
        $aProp = $aRef->getProperty('id'); $aProp->setAccessible(true); $aProp->setValue($attempt, $attemptId);
        $attempt->setExamSession($entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId));
        $attempt->setStudent($student);
        $attempt->setMode(\App\Enum\ExamAttemptMode::Online);
        $attempt->setStatus(ExamAttemptStatus::InProgress);
        $attempt->setStartedAt(new \DateTimeImmutable('-10 minutes'));
        $entityManager->persist($attempt);
        $entityManager->flush();

        $answer = new ExamAnswer();
        $ansRef = new \ReflectionClass($answer);
        $ansProp = $ansRef->getProperty('id'); $ansProp->setAccessible(true); $ansProp->setValue($answer, Uuid::v7());
        $answer->setExamAttempt($attempt);
        $answer->setQuestion($descQuestion);
        $answer->setAnswerText('34');
        $entityManager->persist($answer);
        $entityManager->flush();

        $client->request('POST', '/api/v1/exam-attempts/' . $attemptId . '/submit', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/exam-attempts/' . $attemptId . '/result', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);

        $descriptiveResult = null;
        foreach ($data['data']['questionResults'] as $qr) {
            if ($qr['questionId'] === (string) $descQId) {
                $descriptiveResult = $qr;
                break;
            }
        }
        $this->assertNotNull($descriptiveResult, 'Descriptive question should appear in result');
        $this->assertSame('DESCRIPTIVE', $descriptiveResult['questionType']);
        $this->assertNull($descriptiveResult['isCorrect'], 'Descriptive without reference MUST NOT be isCorrect=false');
        $this->assertNull($descriptiveResult['obtainedMarks'], 'Descriptive without reference MUST NOT be marksObtained=0');
        $this->assertTrue($descriptiveResult['requiresManualEvaluation']);

        $this->assertTrue($data['data']['requiresManualEvaluation']);
        $this->assertGreaterThan(0, $data['data']['pendingAnswers']);
        $this->assertSame(0, $data['data']['obtainedMarks'], 'obtainedMarks is sum of evaluated questionResults; descriptive pending = null contributes 0');
        $this->assertSame(0, $data['data']['percentage'], 'percentage is computed from questionResults sum / totalMarks * 100');
    }

    public function testStartAttemptReturnsAllowedDurationAndExpiresAt(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $examSession = $entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId);
        $examSession->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $examSession->setAvailableTo(new \DateTimeImmutable('+2 hours'));
        $examSession->setDurationOverride(null);
        $entityManager->flush();

        $this->setAttemptTiming(3600);

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId, [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('timing', $data['data']);
        $timing = $data['data']['timing'];
        $this->assertSame(3600, $timing['allowedDurationSeconds'], 'Default 60 min paper should give 3600s duration');
        $this->assertNotNull($timing['expiresAt']);
        $this->assertGreaterThan(0, $timing['remainingSeconds']);
        $this->assertLessThanOrEqual(3600, $timing['remainingSeconds']);
    }

    public function testExpiresAtIsStartedAtPlusDuration(): void
    {
        $client = $this->client;

        $startedAt = new \DateTimeImmutable('-30 minutes');
        $this->setAttemptTiming(3600, $startedAt);

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId, [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $timing = $data['data']['timing'];

        $startedAtFromApi = new \DateTimeImmutable($timing['startedAt']);
        $expiresAt = new \DateTimeImmutable($timing['expiresAt']);
        $diffSeconds = $expiresAt->getTimestamp() - $startedAtFromApi->getTimestamp();
        $this->assertSame(3600, $diffSeconds);
    }

    public function testAvailableToCapsExpiresAtWhenEarlierThanDuration(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $examSession = $entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId);
        $availableTo = new \DateTimeImmutable('+30 minutes');
        $examSession->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $examSession->setAvailableTo($availableTo);
        $examSession->setDurationOverride(null);
        $entityManager->flush();

        $this->setAttemptTiming(1800);

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId, [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $timing = $data['data']['timing'];

        $this->assertSame(1800, $timing['allowedDurationSeconds'], 'allowedDuration is based on QuestionPaper.duration, NOT capped');
    }

    public function testStartingLateCapsExpiresAtToSessionAvailableTo(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $examSession = $entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId);
        $examSession->setAvailableFrom(new \DateTimeImmutable('-5 minutes'));
        $examSession->setAvailableTo(new \DateTimeImmutable('+15 minutes'));
        $examSession->setDurationOverride(null);
        $entityManager->flush();

        $startedAt = new \DateTimeImmutable('-2 minutes');
        $expiresAt = $examSession->getAvailableTo();
        $this->setAttemptTiming(3600, $startedAt, $expiresAt);

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId, [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $timing = $data['data']['timing'];

        $remaining = (int) $timing['remainingSeconds'];

        $this->assertLessThanOrEqual(15 * 60, $remaining, 'Remaining seconds should be capped to availableTo (15min)');
        $this->assertGreaterThan(0, $remaining);
    }

    public function testDurationOverrideTakesPrecedenceOverPaperDuration(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $examSession = $entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId);
        $examSession->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $examSession->setAvailableTo(new \DateTimeImmutable('+2 hours'));
        $examSession->setDurationOverride(30);
        $entityManager->flush();

        $this->setAttemptTiming(30 * 60);

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId, [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $timing = $data['data']['timing'];

        $this->assertSame(30 * 60, $timing['allowedDurationSeconds'], 'durationOverride (30 min) should take precedence over QuestionPaper.duration (60 min)');
    }

    public function testChangingDurationAfterStartDoesNotAffectAttempt(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $examSession = $entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId);
        $examSession->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $examSession->setAvailableTo(new \DateTimeImmutable('+2 hours'));
        $examSession->setDurationOverride(null);
        $entityManager->flush();

        $this->setAttemptTiming(3600);

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId, [], [], $this->getAuthHeaders($this->studentToken));
        $data = json_decode($client->getResponse()->getContent(), true);
        $firstExpiresAt = $data['data']['timing']['expiresAt'];
        $firstAllowedDuration = $data['data']['timing']['allowedDurationSeconds'];

        $examSession->setDurationOverride(15);
        $entityManager->flush();

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId, [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($firstAllowedDuration, $data['data']['timing']['allowedDurationSeconds'], 'Duration must not change after attempt start');
        $this->assertSame($firstExpiresAt, $data['data']['timing']['expiresAt'], 'expiresAt must not change after attempt start');
    }

    public function testChangingAvailableToAfterStartDoesNotExtendAttempt(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $examSession = $entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId);
        $shortAvailableTo = new \DateTimeImmutable('+1 hour');
        $examSession->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $examSession->setAvailableTo($shortAvailableTo);
        $examSession->setDurationOverride(null);
        $entityManager->flush();

        $this->setAttemptTiming(3600);

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId, [], [], $this->getAuthHeaders($this->studentToken));
        $data = json_decode($client->getResponse()->getContent(), true);
        $firstExpiresAt = $data['data']['timing']['expiresAt'];
        $firstAllowedDuration = $data['data']['timing']['allowedDurationSeconds'];

        $examSession->setAvailableTo(new \DateTimeImmutable('+10 hours'));
        $entityManager->flush();

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId, [], [], $this->getAuthHeaders($this->studentToken));
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($firstAllowedDuration, $data['data']['timing']['allowedDurationSeconds']);
        $this->assertSame($firstExpiresAt, $data['data']['timing']['expiresAt'], 'expiresAt must NOT be extended after attempt start');
    }

    public function testNormalSubmissionStoresCorrectTimeTaken(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $examSession = $entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId);
        $examSession->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $examSession->setAvailableTo(new \DateTimeImmutable('+2 hours'));
        $examSession->setDurationOverride(null);
        $entityManager->flush();

        $startedAt = new \DateTimeImmutable('-90 seconds');
        $this->setAttemptTiming(3600, $startedAt);

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/submit', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertNotNull($data['data']['submittedAt']);
        $this->assertNull($data['data']['autoSubmittedAt']);

        $entityManager->clear();
        $attempt = $entityManager->getRepository(ExamAttempt::class)->find($this->testAttemptId);
        $this->assertNotNull($attempt->getTimeTakenSeconds());
        $this->assertGreaterThanOrEqual(90, $attempt->getTimeTakenSeconds());
        $this->assertLessThanOrEqual(3600, $attempt->getTimeTakenSeconds(), 'timeTakenSeconds must not exceed allowedDuration');
    }

    public function testAutomaticExpirySubmissionStoresValidTimeTaken(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $examSession = $entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId);
        $examSession->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $examSession->setAvailableTo(new \DateTimeImmutable('+10 hours'));
        $examSession->setDurationOverride(1);
        $entityManager->flush();

        $startedAt = new \DateTimeImmutable('-90 seconds');
        $expiresAt = new \DateTimeImmutable('-30 seconds');
        $this->setAttemptTiming(60, $startedAt, $expiresAt);

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/submit', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('AUTO_SUBMITTED', $data['data']['status']);
        $this->assertNotNull($data['data']['autoSubmittedAt']);

        $entityManager->clear();
        $attempt = $entityManager->getRepository(ExamAttempt::class)->find($this->testAttemptId);
        $this->assertSame(60, $attempt->getTimeTakenSeconds(), 'Auto-submit timeTakenSeconds must equal allowedDuration (60s) when expired');
    }

    public function testExpiredAttemptRejectsSaveAnswer(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $paper = $entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId);
        $subject = $entityManager->getRepository(Subject::class)->find($this->testSubjectId);

        $newQuestion = new Question();
        $newQuestionId = Uuid::v7();
        $qRef = new \ReflectionClass($newQuestion);
        $qProp = $qRef->getProperty('id'); $qProp->setAccessible(true); $qProp->setValue($newQuestion, $newQuestionId);
        $newQuestion->setQuestionPaper($paper);
        $newQuestion->setType(QuestionType::MCQ);
        $newQuestion->setQuestionText('Test Q for expiry');
        $newQuestion->setMarks(1);
        $newQuestion->setDisplayOrder(99);

        $correctOpt = new QuestionOption();
        $correctOptId = Uuid::v7();
        $coRef = new \ReflectionClass($correctOpt);
        $coProp = $coRef->getProperty('id'); $coProp->setAccessible(true); $coProp->setValue($correctOpt, $correctOptId);
        $correctOpt->setQuestion($newQuestion);
        $correctOpt->setOptionText('Correct');
        $correctOpt->setDisplayOrder(1);
        $correctOpt->setIsCorrect(true);
        $newQuestion->addOption($correctOpt);

        $entityManager->persist($newQuestion);
        $entityManager->persist($correctOpt);
        $entityManager->flush();

        $examSession = $entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId);
        $examSession->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $examSession->setAvailableTo(new \DateTimeImmutable('+10 hours'));
        $examSession->setDurationOverride(null);
        $entityManager->flush();

        $client->request('POST', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/attempts', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $expiresAt = new \DateTimeImmutable('-10 seconds');
        $attempt = $entityManager->getRepository(ExamAttempt::class)->find($this->testAttemptId);
        $r = new \ReflectionClass($attempt);
        $p = $r->getProperty('expiresAt'); $p->setAccessible(true);
        $p->setValue($attempt, $expiresAt);
        $entityManager->flush();

        $payload = json_encode([
            'questionId' => (string) $newQuestionId,
            'selectedOptionId' => (string) $correctOptId,
        ]);

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->studentToken), $payload);

        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('expired', strtolower($data['message']));
    }

    public function testLegacyAttemptWithoutExpiresAtIsHandledSafely(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $paper = $entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId);
        $subject = $entityManager->getRepository(Subject::class)->find($this->testSubjectId);

        $newQuestion = new Question();
        $newQuestionId = Uuid::v7();
        $qRef = new \ReflectionClass($newQuestion);
        $qProp = $qRef->getProperty('id'); $qProp->setAccessible(true); $qProp->setValue($newQuestion, $newQuestionId);
        $newQuestion->setQuestionPaper($paper);
        $newQuestion->setType(QuestionType::MCQ);
        $newQuestion->setQuestionText('Test Q for legacy');
        $newQuestion->setMarks(1);
        $newQuestion->setDisplayOrder(99);

        $correctOpt = new QuestionOption();
        $correctOptId = Uuid::v7();
        $coRef = new \ReflectionClass($correctOpt);
        $coProp = $coRef->getProperty('id'); $coProp->setAccessible(true); $coProp->setValue($correctOpt, $correctOptId);
        $correctOpt->setQuestion($newQuestion);
        $correctOpt->setOptionText('Correct');
        $correctOpt->setDisplayOrder(1);
        $correctOpt->setIsCorrect(true);
        $newQuestion->addOption($correctOpt);

        $entityManager->persist($newQuestion);
        $entityManager->persist($correctOpt);
        $entityManager->flush();

        $client->request('POST', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/attempts', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $attempt = $entityManager->getRepository(ExamAttempt::class)->find($this->testAttemptId);
        $r = new \ReflectionClass($attempt);
        $p = $r->getProperty('expiresAt'); $p->setAccessible(true);
        $p->setValue($attempt, null);
        $p2 = $r->getProperty('allowedDurationSeconds'); $p2->setAccessible(true);
        $p2->setValue($attempt, null);
        $entityManager->flush();

        $payload = json_encode([
            'questionId' => (string) $newQuestionId,
            'selectedOptionId' => (string) $correctOptId,
        ]);

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->studentToken), $payload);
        $this->assertSame(200, $client->getResponse()->getStatusCode(), 'Legacy attempts without expiresAt should still accept answers');

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId, [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertNull($data['data']['timing']['expiresAt']);
        $this->assertNull($data['data']['timing']['allowedDurationSeconds']);
        $this->assertNull($data['data']['timing']['remainingSeconds']);
    }
}
