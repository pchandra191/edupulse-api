<?php
declare(strict_types=1);

namespace App\Tests\Controller\ExamAttemptModule;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
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
        $examSession->setStatus(ExamSessionStatus::Published);
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
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];
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
        $this->assertSame('SUBMITTED', $data['data']['status']);
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
        $this->assertStringContainsString('not eligible', strtolower($data['message']));
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

        $client->request('GET', '/api/v1/exam-attempts/evaluated', ['page' => 1, 'limit' => 100], [], $this->getAuthHeaders($this->superAdminToken));

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
}
