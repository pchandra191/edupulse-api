<?php
declare(strict_types=1);

namespace App\Tests\Controller\ExamAttemptQuestionProgressModule;

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

class ExamAttemptQuestionProgressControllerTest extends WebTestCase
{
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private array $createdEmails = [];
    private string $unique = '';
    private ?\Symfony\Component\Clock\MockClock $mockClock = null;

    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
    private ?Uuid $testClassId = null;
    private ?Uuid $testSectionId = null;
    private ?Uuid $testSubjectId = null;
    private ?Uuid $testQuestionPaperId = null;
    private ?Uuid $testExamSessionId = null;
    private ?Uuid $testStudentId = null;
    private ?Uuid $testAttemptId = null;
    private ?Uuid $testQuestion1Id = null;
    private ?Uuid $testQuestion2Id = null;
    private ?Uuid $testOption1Id = null;
    private ?Uuid $testOption2Id = null;
    private ?string $studentToken = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();

        $this->mockClock = new \Symfony\Component\Clock\MockClock(new \DateTimeImmutable('2025-01-01 10:00:00', new \DateTimeZone('UTC')));
        \Symfony\Component\Clock\Clock::set($this->mockClock);

        $entityManager = $container->get('doctrine')->getManager();

        $this->unique = uniqid();
        $this->testInstituteId = Uuid::v7();
        $this->testAcademicYearId = Uuid::v7();
        $this->testClassId = Uuid::v7();
        $this->testSectionId = Uuid::v7();
        $this->testSubjectId = Uuid::v7();
        $this->testQuestionPaperId = Uuid::v7();
        $this->testExamSessionId = Uuid::v7();
        $this->testStudentId = Uuid::v7();
        $this->testAttemptId = Uuid::v7();
        $this->testQuestion1Id = Uuid::v7();
        $this->testQuestion2Id = Uuid::v7();
        $this->testOption1Id = Uuid::v7();
        $this->testOption2Id = Uuid::v7();

        $student = new User();
        $student->setEmail('student-progress-test-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for Progress');
        $institute->setCode('TESTPROG' . $this->unique);
        $institute->setEmail('test-institute-progress-' . $this->unique . '@edupulse.ai');
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
        $questionPaper->setTitle('Test Question Paper ' . $this->unique);
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
        $examSession->setQuestionPaper($questionPaper);
        $examSession->setCreatedBy($student);
        $examSession->setTitle('Test Exam Session ' . $this->unique);
        $examSession->setAvailableFrom(new \DateTimeImmutable('-1 hour'));
        $examSession->setAvailableTo(new \DateTimeImmutable('+1 hour'));
        $examSession->setTotalMarks(100);
        $examSession->setStatus(ExamSessionStatus::Active);

        $question1 = new Question();
        $q1Reflection = new \ReflectionClass($question1);
        $q1Property = $q1Reflection->getProperty('id');
        $q1Property->setAccessible(true);
        $q1Property->setValue($question1, $this->testQuestion1Id);
        $question1->setQuestionPaper($questionPaper);
        $question1->setType(QuestionType::MCQ);
        $question1->setQuestionText('Test Question 1');
        $question1->setMarks(10);
        $question1->setDisplayOrder(1);

        $question2 = new Question();
        $q2Reflection = new \ReflectionClass($question2);
        $q2Property = $q2Reflection->getProperty('id');
        $q2Property->setAccessible(true);
        $q2Property->setValue($question2, $this->testQuestion2Id);
        $question2->setQuestionPaper($questionPaper);
        $question2->setType(QuestionType::Descriptive);
        $question2->setQuestionText('Test Question 2');
        $question2->setMarks(10);
        $question2->setDisplayOrder(2);

        $option1 = new QuestionOption();
        $o1Reflection = new \ReflectionClass($option1);
        $o1Property = $o1Reflection->getProperty('id');
        $o1Property->setAccessible(true);
        $o1Property->setValue($option1, $this->testOption1Id);
        $option1->setQuestion($question1);
        $option1->setOptionText('Option 1');
        $option1->setDisplayOrder(1);

        $option2 = new QuestionOption();
        $o2Reflection = new \ReflectionClass($option2);
        $o2Property = $o2Reflection->getProperty('id');
        $o2Property->setAccessible(true);
        $o2Property->setValue($option2, $this->testOption2Id);
        $option2->setQuestion($question1);
        $option2->setOptionText('Option 2');
        $option2->setDisplayOrder(2);

        $attempt = new ExamAttempt();
        $aReflection = new \ReflectionClass($attempt);
        $aProperty = $aReflection->getProperty('id');
        $aProperty->setAccessible(true);
        $aProperty->setValue($attempt, $this->testAttemptId);
        $attempt->setExamSession($examSession);
        $attempt->setStudent($student);
        $attempt->setStartedAt(new \DateTimeImmutable());
        $attempt->setMode(\App\Enum\ExamAttemptMode::Online);
        $attempt->setStatus(ExamAttemptStatus::InProgress);

        $entityManager->persist($student);
        $entityManager->persist($institute);
        $entityManager->persist($academicYear);
        $entityManager->persist($academicClass);
        $entityManager->persist($section);
        $entityManager->persist($subject);
        $entityManager->persist($questionPaper);
        $entityManager->persist($examSession);
        $entityManager->persist($question1);
        $entityManager->persist($question2);
        $entityManager->persist($option1);
        $entityManager->persist($option2);
        $entityManager->persist($attempt);
        $entityManager->flush();

        $this->createdEmails = [
            'student-progress-test-' . $this->unique . '@edupulse.ai',
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
            // Ignore cleanup errors
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

    private function getAuthHeaders(): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->studentToken,
        ];
    }

    private function advanceClock(string $modifier): void
    {
        $this->mockClock->modify($modifier);
    }

    private function recordProgress(string $action, ?string $questionId = null, ?string $fromQuestionId = null, ?string $toQuestionId = null): void
    {
        $payload = [
            'action' => $action,
            'questionId' => $questionId,
            'fromQuestionId' => $fromQuestionId,
            'toQuestionId' => $toQuestionId,
        ];

        $this->client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId->toRfc4122() . '/question-progress', [], [], $this->getAuthHeaders(), json_encode($payload));
    }

    private function getProgressList(): array
    {
        $this->client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId->toRfc4122() . '/question-progress', [], [], $this->getAuthHeaders());

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function findProgressItem(array $items, string $questionId): ?array
    {
        foreach ($items as $item) {
            if ($item['questionId'] === $questionId) {
                return $item;
            }
        }

        return null;
    }

    public function testNewQuestionInitiallyHasNotAttempted(): void
    {
        $response = $this->getProgressList();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $items = $response['data']['items'];
        $this->assertCount(2, $items);

        foreach ($items as $item) {
            $this->assertSame('NOT_ATTEMPTED', $item['status']);
            $this->assertSame(0, $item['totalTimeSeconds']);
            $this->assertSame(0, $item['visitCount']);
            $this->assertSame(0, $item['navigationCount']);
        }
    }

    public function testOpeningQuestionCreatesProgress(): void
    {
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $response = $this->getProgressList();
        $items = $response['data']['items'];

        $q1 = $this->findProgressItem($items, $this->testQuestion1Id->toRfc4122());
        $this->assertNotNull($q1);
        $this->assertSame('ATTEMPTED_UNANSWERED', $q1['status']);
        $this->assertSame(1, $q1['visitCount']);
        $this->assertSame(1, $q1['navigationCount']);
        $this->assertNotNull($q1['firstViewedAt']);
        $this->assertNotNull($q1['lastViewedAt']);
    }

    public function testOpeningQuestionChangesStatusToAttemptedUnanswered(): void
    {
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());

        $response = $this->getProgressList();
        $items = $response['data']['items'];

        $q1 = $this->findProgressItem($items, $this->testQuestion1Id->toRfc4122());
        $this->assertSame('ATTEMPTED_UNANSWERED', $q1['status']);
    }

    public function testSavingAnswerChangesStatusToAnswered(): void
    {
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());

        $this->client->request(
            'POST',
            '/api/v1/exam-attempts/' . $this->testAttemptId->toRfc4122() . '/answers',
            [],
            [],
            $this->getAuthHeaders(),
            json_encode([
                'questionId' => $this->testQuestion1Id->toRfc4122(),
                'selectedOptionId' => $this->testOption1Id->toRfc4122(),
            ])
        );

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $response = $this->getProgressList();
        $items = $response['data']['items'];

        $q1 = $this->findProgressItem($items, $this->testQuestion1Id->toRfc4122());
        $this->assertSame('ANSWERED', $q1['status']);
        $this->assertSame(1, $q1['answerChangeCount']);
        $this->assertNotNull($q1['firstAnsweredAt']);
        $this->assertNotNull($q1['lastAnsweredAt']);
    }

    public function testFirstVisitTimeIsRecorded(): void
    {
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());

        $response = $this->getProgressList();
        $items = $response['data']['items'];

        $q1 = $this->findProgressItem($items, $this->testQuestion1Id->toRfc4122());
        $this->assertNotNull($q1['firstViewedAt']);
        $this->assertNotNull($q1['lastViewedAt']);
    }

    public function testLeavingQuestionRecordsElapsedTime(): void
    {
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());
        $this->advanceClock('+10 seconds');
        $this->recordProgress('LEAVE', $this->testQuestion1Id->toRfc4122());

        $response = $this->getProgressList();
        $items = $response['data']['items'];

        $q1 = $this->findProgressItem($items, $this->testQuestion1Id->toRfc4122());
        $this->assertSame(10, $q1['totalTimeSeconds']);
    }

    public function testDuplicateEnterAccumulatesElapsedTime(): void
    {
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());
        $this->advanceClock('+10 seconds');
        $this->recordProgress('LEAVE', $this->testQuestion1Id->toRfc4122());
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());
        $this->advanceClock('+15 seconds');
        $this->recordProgress('LEAVE', $this->testQuestion1Id->toRfc4122());

        $response = $this->getProgressList();
        $items = $response['data']['items'];

        $q1 = $this->findProgressItem($items, $this->testQuestion1Id->toRfc4122());
        $this->assertSame(25, $q1['totalTimeSeconds']);
        $this->assertSame(2, $q1['visitCount']);
    }

    public function testDuplicateEnterDoesNotInflateVisitCount(): void
    {
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());

        $response = $this->getProgressList();
        $items = $response['data']['items'];

        $q1 = $this->findProgressItem($items, $this->testQuestion1Id->toRfc4122());
        $this->assertSame(1, $q1['visitCount']);
        $this->assertSame(1, $q1['navigationCount']);
    }

    public function testTenSecondsPlusFifteenEqualsTwentyFive(): void
    {
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());
        $this->advanceClock('+10 seconds');
        $this->recordProgress('LEAVE', $this->testQuestion1Id->toRfc4122());
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());
        $this->advanceClock('+15 seconds');
        $this->recordProgress('LEAVE', $this->testQuestion1Id->toRfc4122());

        $response = $this->getProgressList();
        $items = $response['data']['items'];

        $q1 = $this->findProgressItem($items, $this->testQuestion1Id->toRfc4122());
        $this->assertSame(25, $q1['totalTimeSeconds']);
        $this->assertSame(2, $q1['visitCount']);
    }

    public function testNextAccumulatesCurrentQuestionTime(): void
    {
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());
        $this->advanceClock('+12 seconds');
        $this->recordProgress('NEXT', null, $this->testQuestion1Id->toRfc4122(), $this->testQuestion2Id->toRfc4122());

        $response = $this->getProgressList();
        $items = $response['data']['items'];

        $q1 = $this->findProgressItem($items, $this->testQuestion1Id->toRfc4122());
        $this->assertSame(12, $q1['totalTimeSeconds']);
    }

    public function testPreviousAccumulatesCurrentQuestionTime(): void
    {
        $this->recordProgress('ENTER', $this->testQuestion2Id->toRfc4122());
        $this->advanceClock('+10 seconds');
        $this->recordProgress('PREVIOUS', null, $this->testQuestion2Id->toRfc4122(), $this->testQuestion1Id->toRfc4122());

        $response = $this->getProgressList();
        $items = $response['data']['items'];

        $q2 = $this->findProgressItem($items, $this->testQuestion2Id->toRfc4122());
        $this->assertSame(10, $q2['totalTimeSeconds']);
    }

    public function testSidebarNavigationAccumulatesCurrentQuestionTime(): void
    {
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());
        $this->advanceClock('+15 seconds');
        $this->recordProgress('NEXT', null, $this->testQuestion1Id->toRfc4122(), $this->testQuestion2Id->toRfc4122());

        $response = $this->getProgressList();
        $items = $response['data']['items'];

        $q1 = $this->findProgressItem($items, $this->testQuestion1Id->toRfc4122());
        $this->assertSame(15, $q1['totalTimeSeconds']);
    }

    public function testDuplicateLeaveDoesNotDoubleCount(): void
    {
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());
        $this->advanceClock('+10 seconds');
        $this->recordProgress('LEAVE', $this->testQuestion1Id->toRfc4122());
        $this->advanceClock('+5 seconds');
        $this->recordProgress('LEAVE', $this->testQuestion1Id->toRfc4122());

        $response = $this->getProgressList();
        $items = $response['data']['items'];

        $q1 = $this->findProgressItem($items, $this->testQuestion1Id->toRfc4122());
        $this->assertSame(10, $q1['totalTimeSeconds']);
    }

    public function testDuplicateEnterDoesNotLoseElapsedTime(): void
    {
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());
        $this->advanceClock('+20 seconds');
        $this->recordProgress('LEAVE', $this->testQuestion1Id->toRfc4122());
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());
        $this->advanceClock('+10 seconds');
        $this->recordProgress('NEXT', null, $this->testQuestion1Id->toRfc4122(), $this->testQuestion2Id->toRfc4122());

        $response = $this->getProgressList();
        $items = $response['data']['items'];

        $q1 = $this->findProgressItem($items, $this->testQuestion1Id->toRfc4122());
        $this->assertSame(30, $q1['totalTimeSeconds']);
    }

    public function testReturningToQuestionAccumulatesAdditionalTime(): void
    {
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());
        $this->advanceClock('+10 seconds');
        $this->recordProgress('LEAVE', $this->testQuestion1Id->toRfc4122());
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());
        $this->advanceClock('+15 seconds');
        $this->recordProgress('LEAVE', $this->testQuestion1Id->toRfc4122());

        $response = $this->getProgressList();
        $items = $response['data']['items'];

        $q1 = $this->findProgressItem($items, $this->testQuestion1Id->toRfc4122());
        $this->assertSame(25, $q1['totalTimeSeconds']);
        $this->assertSame(2, $q1['visitCount']);
    }

    public function testSubmitClosesActiveQuestionTiming(): void
    {
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());
        $this->advanceClock('+7 seconds');

        $this->client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId->toRfc4122() . '/submit', [], [], $this->getAuthHeaders());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testStudentCannotModifyAnotherStudentsAttempt(): void
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

        $otherStudent = new User();
        $otherStudentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($otherStudent);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($otherStudent, $otherStudentId);
        $otherStudent->setEmail('other-student-progress-test-' . $this->unique . '@edupulse.ai');
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();

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

        $payload = json_encode([
            'action' => 'ENTER',
            'questionId' => (string) $this->testQuestion1Id,
        ]);

        $this->client->request('POST', '/api/v1/exam-attempts/' . $otherAttemptId . '/question-progress', [], [], $this->getAuthHeaders(), $payload);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testQuestionFromAnotherAttemptIsRejected(): void
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

        $otherSubject = new Subject();
        $otherSubjectId = Uuid::v7();
        $subjectReflection = new \ReflectionClass($otherSubject);
        $subjectProperty = $subjectReflection->getProperty('id');
        $subjectProperty->setAccessible(true);
        $subjectProperty->setValue($otherSubject, $otherSubjectId);
        $otherSubject->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $otherSubject->setName('Other Subject ' . $this->unique);
        $otherSubject->setCode('OSUB' . $this->unique);
        $otherSubject->setStatus(SubjectStatus::Active);

        $otherPaper = new QuestionPaper();
        $otherPaperId = Uuid::v7();
        $qpReflection = new \ReflectionClass($otherPaper);
        $qpProperty = $qpReflection->getProperty('id');
        $qpProperty->setAccessible(true);
        $qpProperty->setValue($otherPaper, $otherPaperId);
        $otherPaper->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $otherPaper->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $otherPaper->setSubject($otherSubject);
        $otherPaper->setTitle('Other Question Paper ' . $this->unique);
        $otherPaper->setDate(new \DateTimeImmutable('2025-05-01'));
        $otherPaper->setDuration(60);
        $otherPaper->setMaximumMarks(100);
        $otherPaper->setStatus(QuestionPaperStatus::Finalized);

        $otherQuestion = new Question();
        $otherQReflection = new \ReflectionClass($otherQuestion);
        $otherQProperty = $otherQReflection->getProperty('id');
        $otherQProperty->setAccessible(true);
        $otherQProperty->setValue($otherQuestion, Uuid::v7());
        $otherQuestion->setQuestionPaper($otherPaper);
        $otherQuestion->setType(QuestionType::MCQ);
        $otherQuestion->setQuestionText('Other Question');
        $otherQuestion->setMarks(10);
        $otherQuestion->setDisplayOrder(1);

        $entityManager->persist($otherSubject);
        $entityManager->persist($otherPaper);
        $entityManager->persist($otherQuestion);
        $entityManager->flush();

        $payload = json_encode([
            'action' => 'ENTER',
            'questionId' => (string) $otherQuestion->getId(),
        ]);

        $this->client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId->toRfc4122() . '/question-progress', [], [], $this->getAuthHeaders(), $payload);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testOfflineAttemptRejectsOnlineProgress(): void
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $attempt = $entityManager->getRepository(ExamAttempt::class)->find($this->testAttemptId);
        $attempt->setMode(\App\Enum\ExamAttemptMode::Offline);
        $entityManager->flush();

        $payload = json_encode([
            'action' => 'ENTER',
            'questionId' => (string) $this->testQuestion1Id,
        ]);

        $this->client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId->toRfc4122() . '/question-progress', [], [], $this->getAuthHeaders(), $payload);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testExistingAnswerProducesAnsweredStatus(): void
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

        $answer = new ExamAnswer();
        $answer->setExamAttempt($entityManager->getRepository(ExamAttempt::class)->find($this->testAttemptId));
        $answer->setQuestion($entityManager->getRepository(Question::class)->find($this->testQuestion1Id));
        $answer->setAnswerText('Pre-existing answer');
        $entityManager->persist($answer);
        $entityManager->flush();

        $response = $this->getProgressList();
        $items = $response['data']['items'];

        $q1 = $this->findProgressItem($items, $this->testQuestion1Id->toRfc4122());
        $this->assertSame('ANSWERED', $q1['status']);
    }

    public function testDuplicateNavigationDoesNotDoubleCountElapsedTime(): void
    {
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());
        $this->advanceClock('+10 seconds');
        $this->recordProgress('LEAVE', $this->testQuestion1Id->toRfc4122());

        $firstResponse = $this->getProgressList();
        $firstTime = $firstResponse['data']['items'][0]['totalTimeSeconds'];

        $this->advanceClock('+5 seconds');
        $this->recordProgress('LEAVE', $this->testQuestion1Id->toRfc4122());

        $response = $this->getProgressList();
        $items = $response['data']['items'];

        $q1 = $this->findProgressItem($items, $this->testQuestion1Id->toRfc4122());
        $this->assertSame($firstTime, $q1['totalTimeSeconds']);
    }

    public function testSubmittedAttemptRejectsFurtherProgressUpdates(): void
    {
        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());

        $this->client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId->toRfc4122() . '/submit', [], [], $this->getAuthHeaders());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testMultipleProgressRecordsCannotExistForSameAttemptQuestion(): void
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

        $this->recordProgress('ENTER', $this->testQuestion1Id->toRfc4122());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $count = $entityManager->getRepository(\App\Entity\ExamAttemptQuestionProgress::class)
            ->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.examAttempt = :attempt')
            ->andWhere('p.question = :question')
            ->setParameter('attempt', $this->testAttemptId)
            ->setParameter('question', $this->testQuestion1Id)
            ->getQuery()
            ->getSingleScalarResult();

        $this->assertSame(1, (int) $count);
    }
}
