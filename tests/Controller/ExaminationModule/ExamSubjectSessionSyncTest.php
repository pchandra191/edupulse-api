<?php
declare(strict_types=1);

namespace App\Tests\Controller\ExaminationModule;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\ExamSession;
use App\Entity\ExamSubject;
use App\Entity\Examination;
use App\Entity\Institute;
use App\Entity\StudentEnrollment;
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
use App\Enum\StudentEnrollmentStatus;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\ExamSessionRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class ExamSubjectSessionSyncTest extends WebTestCase
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
    private ?Uuid $testExaminationId = null;
    private ?Uuid $testExamSubjectId = null;
    private ?Uuid $testQuestionPaperId = null;
    private ?string $superAdminToken = null;
    private ?string $instituteToken = null;
    private ?string $studentToken = null;

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
        $this->testExaminationId = Uuid::v7();
        $this->testExamSubjectId = Uuid::v7();
        $this->testQuestionPaperId = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-sy-' . $this->unique . '@edupulse.ai');
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
        $institute->setName('Test Institute Sync');
        $institute->setCode('TISY' . $this->unique);
        $institute->setEmail('test-inst-sy-' . $this->unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);
        $institute->setTimezone(\App\Enum\Timezone::INR);

        $academicYear = new AcademicYear();
        $ayReflection = new \ReflectionClass($academicYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($academicYear, $this->testAcademicYearId);
        $academicYear->setInstitute($institute);
        $academicYear->setName('Test Academic Year Sync');
        $academicYear->setCode('AYSY' . $this->unique);
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
        $academicClass->setName('Test Class Sync');
        $academicClass->setCode('CLSSY' . $this->unique);
        $academicClass->setStatus(ClassStatus::Active);

        $section = new Section();
        $sectionReflection = new \ReflectionClass($section);
        $sectionProperty = $sectionReflection->getProperty('id');
        $sectionProperty->setAccessible(true);
        $sectionProperty->setValue($section, $this->testSectionId);
        $section->setAcademicClass($academicClass);
        $section->setName('Test Section Sync');
        $section->setCode('SSY' . $this->unique);
        $section->setStatus(\App\Enum\SectionStatus::Active);

        $subject = new Subject();
        $subjectReflection = new \ReflectionClass($subject);
        $subjectProperty = $subjectReflection->getProperty('id');
        $subjectProperty->setAccessible(true);
        $subjectProperty->setValue($subject, $this->testSubjectId);
        $subject->setInstitute($institute);
        $subject->setName('Test Subject Sync');
        $subject->setCode('SUBY' . $this->unique);
        $subject->setStatus(SubjectStatus::Active);

        $examination = new Examination();
        $examination->setInstitute($institute);
        $examination->setAcademicYear($academicYear);
        $examination->setAcademicClass($academicClass);
        $examination->setName('Test Examination Sync');
        $examination->setCode('EXSY' . $this->unique);
        $examination->setStartDate(new \DateTimeImmutable('2025-06-01'));
        $examination->setEndDate(new \DateTimeImmutable('2025-06-30'));
        $examination->setStatus(ExaminationStatus::Draft);

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

        $questionPaper = new QuestionPaper();
        $qpReflection = new \ReflectionClass($questionPaper);
        $qpProperty = $qpReflection->getProperty('id');
        $qpProperty->setAccessible(true);
        $qpProperty->setValue($questionPaper, $this->testQuestionPaperId);
        $questionPaper->setInstitute($institute);
        $questionPaper->setAcademicYear($academicYear);
        $questionPaper->setSubject($subject);
        $questionPaper->setTitle('Test Question Paper Sync');
        $questionPaper->setDate(new \DateTimeImmutable('2025-05-01'));
        $questionPaper->setDuration(60);
        $questionPaper->setMaximumMarks(100);
        $questionPaper->setStatus(QuestionPaperStatus::Finalized);

        $instituteUser = new User();
        $instituteUser->setEmail('institute-sy-' . $this->unique . '@edupulse.ai');
        $instituteUser->setPassword(password_hash('Institute123!', PASSWORD_BCRYPT));
        $instituteUser->setRoles([UserRole::Institute->value]);
        $instituteUser->setStatus(UserStatus::Active);
        $instituteUser->verify();
        $instituteUser->setInstitute($institute);

        $student = new User();
        $student->setEmail('student-sy-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($institute);
        $student->setFirstName('Test');
        $student->setLastName('Student');

        $this->entityManager->persist($superAdmin);
        $this->entityManager->persist($institute);
        $this->entityManager->persist($academicYear);
        $this->entityManager->persist($academicClass);
        $this->entityManager->persist($section);
        $this->entityManager->persist($subject);
        $this->entityManager->persist($examination);
        $this->entityManager->persist($examSubject);
        $this->entityManager->persist($questionPaper);
        $this->entityManager->persist($instituteUser);
        $this->entityManager->persist($student);
        $this->entityManager->flush();

        $this->createdEmails = [
            'superadmin-sy-' . $this->unique . '@edupulse.ai',
            'institute-sy-' . $this->unique . '@edupulse.ai',
            'student-sy-' . $this->unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login('superadmin-sy-' . $this->unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->instituteToken = $this->login('institute-sy-' . $this->unique . '@edupulse.ai', 'Institute123!');
        $this->studentToken = $this->login('student-sy-' . $this->unique . '@edupulse.ai', 'Student123!');
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
            $entityManager->getConnection()->commit();
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

    private function createStudentEnrollment(User $student, AcademicYear $academicYear, AcademicClass $academicClass, ?Section $section = null): StudentEnrollment
    {
        $entityManager = $this->entityManager;

        $student = $entityManager->getRepository(User::class)->find($student->getId());
        $academicYear = $entityManager->getRepository(AcademicYear::class)->find($academicYear->getId());
        $academicClass = $entityManager->getRepository(AcademicClass::class)->find($academicClass->getId());

        $enrollment = new StudentEnrollment();
        $enrollment->setStudent($student);
        $enrollment->setAcademicYear($academicYear);
        $enrollment->setAcademicClass($academicClass);
        $enrollment->setAdmissionNumber('STU-' . $this->unique . '-' . uniqid());
        $enrollment->setStatus(StudentEnrollmentStatus::Active);
        $enrollment->setIsCurrent(true);
        $enrollment->setInstituteId($student->getInstitute()->getId());

        $entityManager->persist($enrollment);
        $entityManager->flush();

        if ($section !== null) {
            $section = $entityManager->getRepository(Section::class)->find($section->getId());
            $enrollmentSection = new \App\Entity\EnrollmentSection();
            $enrollmentSection->setStudentEnrollment($enrollment);
            $enrollmentSection->setSection($section);
            $enrollmentSection->setStatus(\App\Enum\EnrollmentSectionStatus::Active);

            $entityManager->persist($enrollmentSection);
            $entityManager->flush();
        }

        return $enrollment;
    }

    private function getExamSubjectResponse(bool $withAttach = false, ?string $token = null): array
    {
        if ($withAttach) {
            $payload = json_encode([
                'questionPaperId' => (string) $this->testQuestionPaperId,
            ]);
            $this->client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($token ?? $this->superAdminToken), $payload);
        }

        $this->client->request('GET', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId, [], [], $this->getAuthHeaders($token ?? $this->superAdminToken));

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testSessionCreatedWhenScheduledWithQuestionPaper(): void
    {
        $data = $this->getExamSubjectResponse(true);

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertNotEmpty($data['success']);
        $this->assertNotNull($data['data']['examSession']);
        $this->assertSame('SCHEDULED', $data['data']['examSession']['status']);
    }

    public function testNoSessionCreatedForDraftStatus(): void
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setStatus(ExaminationStatus::Draft);
        $entityManager->flush();

        $data = $this->getExamSubjectResponse(true);

        $this->assertNull($data['data']['examSession']);
    }

    public function testNoSessionCreatedForScheduledWithoutQuestionPaper(): void
    {
        $data = $this->getExamSubjectResponse(false);

        $this->assertNull($data['data']['examSession']);
    }

    public function testNoDuplicateSessionsOnResync(): void
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $examSessionRepo = $entityManager->getRepository(ExamSession::class);

        $this->getExamSubjectResponse(true);

        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $firstSession = $examSessionRepo->findByExamSubject($this->testExamSubjectId, $this->testInstituteId);

        $examSubject->setRemarks('Updated remarks');
        $entityManager->flush();

        $this->getExamSubjectResponse(false);

        $secondSession = $examSessionRepo->findByExamSubject($this->testExamSubjectId, $this->testInstituteId);

        $this->assertNotNull($firstSession);
        $this->assertNotNull($secondSession);
        $this->assertTrue($firstSession->getId()->equals($secondSession->getId()));
    }

    public function testStudentCanDiscoverSession(): void
    {
        $student = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'student-sy-' . $this->unique . '@edupulse.ai']);
        $this->createStudentEnrollment($student, $this->entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId), $this->entityManager->getRepository(AcademicClass::class)->find($this->testClassId));

        $this->getExamSubjectResponse(true);

        $this->client->request('GET', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertGreaterThanOrEqual(1, $data['data']['pagination']['totalItems'] ?? count($data['data'] ?? []));
    }

    public function testActiveTimeWindowAccess(): void
    {
        $student = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'student-sy-' . $this->unique . '@edupulse.ai']);
        $this->createStudentEnrollment($student, $this->entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId), $this->entityManager->getRepository(AcademicClass::class)->find($this->testClassId));

        $this->getExamSubjectResponse(true);

        $this->client->request('GET', '/api/v1/exam-sessions', [], [], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testDeniedBeforeAvailableFrom(): void
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $futureDate = new \DateTimeImmutable('2030-12-01');
        $examSubject->setExamDate($futureDate);
        $examSubject->setStartTime(new \DateTimeImmutable('2030-12-01 09:00:00'));
        $examSubject->setEndTime(new \DateTimeImmutable('2030-12-01 11:00:00'));
        $entityManager->flush();

        $this->getExamSubjectResponse(true);

        $session = $entityManager->getRepository(ExamSession::class)->findByExamSubject($this->testExamSubjectId, $this->testInstituteId);
        $this->assertNotNull($session);

        $now = new \DateTimeImmutable();
        $this->assertGreaterThan($now, $session->getAvailableFrom());
    }

    public function testDeniedAfterAvailableTo(): void
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $pastDate = new \DateTimeImmutable('2020-01-01');
        $examSubject->setExamDate($pastDate);
        $examSubject->setStartTime(new \DateTimeImmutable('2020-01-01 09:00:00'));
        $examSubject->setEndTime(new \DateTimeImmutable('2020-01-01 11:00:00'));
        $entityManager->flush();

        $this->getExamSubjectResponse(true);

        $session = $entityManager->getRepository(ExamSession::class)->findByExamSubject($this->testExamSubjectId, $this->testInstituteId);
        $this->assertNotNull($session);

        $now = new \DateTimeImmutable();
        $this->assertLessThan($now, $session->getAvailableTo());
    }

public function testExistingSessionLinkedNotRecreated(): void
        {
            $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
            $examSessionRepo = $entityManager->getRepository(ExamSession::class);

            $this->getExamSubjectResponse(true);

            $session = $examSessionRepo->findByExamSubject($this->testExamSubjectId, $this->testInstituteId);
            $originalSessionId = $session->getId();

            $updatePayload = json_encode([
                'maxMarks' => 200,
                'passingMarks' => 80,
            ]);
            $this->client->request('PUT', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId, [], [], $this->getAuthHeaders($this->superAdminToken), $updatePayload);

            $this->assertSame(200, $this->client->getResponse()->getStatusCode());

            $entityManager->clear();
            $updatedSession = $examSessionRepo->findByExamSubject($this->testExamSubjectId, $this->testInstituteId);
            
            // Debug
            if ($updatedSession === null) {
                var_dump('Session not found after update');
                $allSessions = $examSessionRepo->findBy(['examination' => $this->testExaminationId]);
                var_dump('All sessions for exam: ' . count($allSessions));
                foreach ($allSessions as $s) {
                    var_dump('Session: ' . $s->getId() . ' examSubject: ' . ($s->getExamSubject() ? $s->getExamSubject()->getId() : 'null') . ' status: ' . $s->getStatus()->value);
                }
            }

            $this->assertTrue($originalSessionId->equals($updatedSession->getId()));
            $this->assertSame(200, $updatedSession->getTotalMarks());
        }

    public function testSessionArchivedWhenNoQuestionPaper(): void
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $examSessionRepo = $entityManager->getRepository(ExamSession::class);

        $this->getExamSubjectResponse(true);

        $session = $examSessionRepo->findByExamSubject($this->testExamSubjectId, $this->testInstituteId);
        $this->assertNotNull($session);
        $this->assertNotSame('ARCHIVED', $session->getStatus()->value);

        $client = $this->client;
        $client->request('DELETE', '/api/v1/examinations/' . $this->testExaminationId . '/subjects/' . $this->testExamSubjectId . '/question-paper', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $entityManager->clear();
        $updatedSession = $examSessionRepo->findByExamSubject($this->testExamSubjectId, $this->testInstituteId);
        $this->assertNotNull($updatedSession);
        $this->assertSame('ARCHIVED', $updatedSession->getStatus()->value);
    }

    public function testExamSubjectResponseIncludesSessionSummary(): void
    {
        $data = $this->getExamSubjectResponse(true);

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('examSession', $data['data']);
        $this->assertNotNull($data['data']['examSession']);
        $this->assertArrayHasKey('id', $data['data']['examSession']);
        $this->assertArrayHasKey('status', $data['data']['examSession']);
        $this->assertArrayHasKey('availableFrom', $data['data']['examSession']);
        $this->assertArrayHasKey('availableTo', $data['data']['examSession']);
    }

    public function testScheduledMapsToScheduledStatus(): void
    {
        $data = $this->getExamSubjectResponse(true);

        $this->assertSame('SCHEDULED', $data['data']['examSession']['status']);
    }

    public function testSessionAvailableFromAndToSetCorrectly(): void
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $examSessionRepo = $entityManager->getRepository(ExamSession::class);

        $examSubject = $entityManager->getRepository(ExamSubject::class)->find($this->testExamSubjectId);
        $examSubject->setExamDate(new \DateTimeImmutable('2025-12-15'));
        $examSubject->setStartTime(new \DateTimeImmutable('2025-12-15 14:30:00'));
        $examSubject->setEndTime(new \DateTimeImmutable('2025-12-15 16:30:00'));
        $entityManager->flush();

        $this->getExamSubjectResponse(true);

        $session = $examSessionRepo->findByExamSubject($this->testExamSubjectId, $this->testInstituteId);
        $this->assertNotNull($session);

        $expectedFrom = new \DateTimeImmutable('2025-12-15 14:30:00', new \DateTimeZone('Asia/Kolkata'));
        $expectedFrom = $expectedFrom->setTimezone(new \DateTimeZone('UTC'));
        $expectedTo = new \DateTimeImmutable('2025-12-15 16:30:00', new \DateTimeZone('Asia/Kolkata'));
        $expectedTo = $expectedTo->setTimezone(new \DateTimeZone('UTC'));

        $this->assertEquals($expectedFrom->format('Y-m-d H:i:s'), $session->getAvailableFrom()->format('Y-m-d H:i:s'));
        $this->assertEquals($expectedTo->format('Y-m-d H:i:s'), $session->getAvailableTo()->format('Y-m-d H:i:s'));
    }
}
