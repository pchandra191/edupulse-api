<?php
declare(strict_types=1);

namespace App\Tests\Controller\ExamSubmissionDocumentModule;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\ExamAttempt;
use App\Entity\ExamSession;
use App\Entity\Institute;
use App\Entity\QuestionPaper;
use App\Entity\Section;
use App\Entity\StudentEnrollment;
use App\Entity\Subject;
use App\Entity\TeacherAssignment;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\ExamAttemptStatus;
use App\Enum\ExamSessionStatus;
use App\Enum\InstituteStatus;
use App\Enum\QuestionPaperStatus;
use App\Enum\SectionStatus;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\SubjectStatus;
use App\Enum\TeacherAssignmentStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

class ExamSubmissionDocumentControllerTest extends WebTestCase
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
    private ?Uuid $testTeacherId = null;
    private ?string $studentToken = null;
    private ?string $teacherToken = null;
    private ?string $superAdminToken = null;

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
        $this->testTeacherId = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-doc-test-' . $this->unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for Documents');
        $institute->setCode('TESTDOC' . $this->unique);
        $institute->setEmail('test-institute-doc-' . $this->unique . '@edupulse.ai');
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
        $examSession->setTitle('Test Exam Session ' . $this->unique);
        $examSession->setAvailableFrom(new \DateTimeImmutable('2025-01-01 09:00:00'));
        $examSession->setAvailableTo(new \DateTimeImmutable('2026-12-31 23:59:59'));
        $examSession->setTotalMarks(100);
        $examSession->setStatus(ExamSessionStatus::Published);
        $examSession->setCreatedBy($superAdmin);

        $student = new User();
        $studentReflection = new \ReflectionClass($student);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($student, $this->testStudentId);
        $student->setEmail('student-doc-test-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($institute);

        $teacher = new User();
        $teacherReflection = new \ReflectionClass($teacher);
        $teacherProperty = $teacherReflection->getProperty('id');
        $teacherProperty->setAccessible(true);
        $teacherProperty->setValue($teacher, $this->testTeacherId);
        $teacher->setEmail('teacher-doc-test-' . $this->unique . '@edupulse.ai');
        $teacher->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
        $teacher->setRoles([UserRole::Teacher->value]);
        $teacher->setStatus(UserStatus::Active);
        $teacher->verify();
        $teacher->setInstitute($institute);

        $attempt = new ExamAttempt();
        $attempt->setExamSession($examSession);
        $attempt->setStudent($student);
        $attempt->setStatus(ExamAttemptStatus::InProgress);
        $attempt->setStartedAt(new \DateTimeImmutable());

        $enrollment = new StudentEnrollment();
        $enrollment->setStudent($student);
        $enrollment->setAcademicClass($academicClass);
        $enrollment->setStatus(StudentEnrollmentStatus::Active);

        $teacherAssignment = new TeacherAssignment();
        $teacherAssignment->setTeacher($teacher);
        $teacherAssignment->setAcademicClass($academicClass);
        $teacherAssignment->setStatus(TeacherAssignmentStatus::Active);

        $entityManager->persist($superAdmin);
        $entityManager->persist($institute);
        $entityManager->persist($academicYear);
        $entityManager->persist($academicClass);
        $entityManager->persist($section);
        $entityManager->persist($subject);
        $entityManager->persist($questionPaper);
        $entityManager->persist($examSession);
        $entityManager->persist($student);
        $entityManager->persist($teacher);
        $entityManager->persist($attempt);
        $entityManager->persist($enrollment);
        $entityManager->persist($teacherAssignment);
        $entityManager->flush();

        $this->createdEmails = [
            'superadmin-doc-test-' . $this->unique . '@edupulse.ai',
            'student-doc-test-' . $this->unique . '@edupulse.ai',
            'teacher-doc-test-' . $this->unique . '@edupulse.ai',
        ];

        $this->studentToken = $this->login('student-doc-test-' . $this->unique . '@edupulse.ai', 'Student123!');
        $this->teacherToken = $this->login('teacher-doc-test-' . $this->unique . '@edupulse.ai', 'Teacher123!');
        $this->superAdminToken = $this->login('superadmin-doc-test-' . $this->unique . '@edupulse.ai', 'SuperAdmin123!');
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

    private function getAuthHeaders(string $token): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];
    }

    private function createTempPdf(string $filename = 'test.pdf'): UploadedFile
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'doc_') . '.pdf';
        file_put_contents($tempFile, '%PDF-1.4 test content');

        return new UploadedFile(
            $tempFile,
            $filename,
            'application/pdf',
            null,
            true
        );
    }

    private function createTempImage(string $filename = 'test.jpg'): UploadedFile
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'doc_') . '.jpg';
        file_put_contents($tempFile, 'image test content');

        return new UploadedFile(
            $tempFile,
            $filename,
            'image/jpeg',
            null,
            true
        );
    }

    public function testStudentCanUploadPdfDocument(): void
    {
        $client = $this->client;
        $file = $this->createTempPdf('answer-sheet.pdf');

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/documents', [
            'documentType' => 'ANSWER_SHEET',
        ], [
            'file' => $file,
        ], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('ANSWER_SHEET', $data['data']['documentType']);
        $this->assertSame('application/pdf', $data['data']['mimeType']);
    }

    public function testStudentCanUploadImageDocument(): void
    {
        $client = $this->client;
        $file = $this->createTempImage('handwritten.jpg');

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/documents', [
            'documentType' => 'HANDWRITTEN',
        ], [
            'file' => $file,
        ], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('HANDWRITTEN', $data['data']['documentType']);
        $this->assertSame('image/jpeg', $data['data']['mimeType']);
    }

    public function testUploadRejectsInvalidFileType(): void
    {
        $client = $this->client;
        $tempFile = tempnam(sys_get_temp_dir(), 'doc_');
        file_put_contents($tempFile, 'not a valid file');

        $file = new UploadedFile(
            $tempFile,
            'test.txt',
            'text/plain',
            null,
            true
        );

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/documents', [
            'documentType' => 'ANSWER_SHEET',
        ], [
            'file' => $file,
        ], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('file', $data['errors']);
        $this->assertStringContainsString('Invalid file type', $data['errors']['file'][0]);
    }

    public function testUploadRequiresFile(): void
    {
        $client = $this->client;

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/documents', [
            'documentType' => 'ANSWER_SHEET',
        ], [], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('file', $data['errors']);
        $this->assertStringContainsString('File is required', $data['errors']['file'][0]);
    }

    public function testStudentCanListDocuments(): void
    {
        $client = $this->client;

        $file = $this->createTempPdf('list-test.pdf');
        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/documents', [
            'documentType' => 'ANSWER_SHEET',
        ], [
            'file' => $file,
        ], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId . '/documents', [], [], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('documents', $data['data']);
        $this->assertCount(1, $data['data']['documents']);
        $this->assertSame('list-test.pdf', $data['data']['documents'][0]['originalFilename']);
    }

    public function testStudentCanDeleteOwnDocument(): void
    {
        $client = $this->client;

        $file = $this->createTempPdf('delete-test.pdf');
        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/documents', [
            'documentType' => 'ANSWER_SHEET',
        ], [
            'file' => $file,
        ], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $uploadData = json_decode($client->getResponse()->getContent(), true);
        $documentId = $uploadData['data']['id'];

        $client->request('DELETE', '/api/v1/exam-attempts/' . $this->testAttemptId . '/documents/' . $documentId, [], [], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testTeacherCanViewDocumentsForClassAttempt(): void
    {
        $client = $this->client;

        $file = $this->createTempPdf('teacher-view.pdf');
        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/documents', [
            'documentType' => 'ANSWER_SHEET',
        ], [
            'file' => $file,
        ], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId . '/documents', [], [], $this->getAuthHeaders($this->teacherToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['data']['documents']);
    }

    public function testMaxDocumentsEnforced(): void
    {
        $client = $this->client;

        for ($i = 0; $i < 3; $i++) {
            $file = $this->createTempPdf('doc-' . $i . '.pdf');
            $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/documents', [
                'documentType' => 'ANSWER_SHEET',
            ], [
                'file' => $file,
            ], $this->getAuthHeaders($this->studentToken));
            $this->assertSame(201, $client->getResponse()->getStatusCode(), 'Upload ' . $i . ' should succeed');
        }

        $file = $this->createTempPdf('doc-overflow.pdf');
        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/documents', [
            'documentType' => 'ANSWER_SHEET',
        ], [
            'file' => $file,
        ], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Maximum', $data['message']);
    }

    public function testUnauthorizedAccessReturns404(): void
    {
        $client = $this->client;

        $otherStudent = new User();
        $otherStudent->setEmail('other-student-doc-' . $this->unique . '@edupulse.ai');
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();
        $otherStudent->setInstitute($client->getContainer()->get('doctrine')->getManager()->getRepository(Institute::class)->find($this->testInstituteId));

        $otherAttempt = new ExamAttempt();
        $otherAttempt->setExamSession($client->getContainer()->get('doctrine')->getManager()->getRepository(\App\Entity\ExamSession::class)->find($this->testExamSessionId));
        $otherAttempt->setStudent($otherStudent);
        $otherAttempt->setStatus(ExamAttemptStatus::InProgress);
        $otherAttempt->setStartedAt(new \DateTimeImmutable());

        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        $entityManager->persist($otherStudent);
        $entityManager->persist($otherAttempt);
        $entityManager->flush();

        $otherToken = $this->login('other-student-doc-' . $this->unique . '@edupulse.ai', 'Student123!');

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId . '/documents', [], [], $this->getAuthHeaders($otherToken));

        $this->assertSame(404, $client->getResponse()->getStatusCode());

        $entityManager->remove($otherAttempt);
        $entityManager->remove($otherStudent);
        $entityManager->flush();
    }

    public function testStudentCanViewOwnDocuments(): void
    {
        $client = $this->client;

        $file = $this->createTempPdf('student-view.pdf');
        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/documents', [
            'documentType' => 'ANSWER_SHEET',
        ], [
            'file' => $file,
        ], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId . '/documents', [], [], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['data']['documents']);
    }

    public function testDeleteNonExistentDocumentReturns404(): void
    {
        $client = $this->client;

        $fakeDocumentId = Uuid::v7()->toRfc4122();

        $client->request('DELETE', '/api/v1/exam-attempts/' . $this->testAttemptId . '/documents/' . $fakeDocumentId, [], [], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testUploadRejectsFileTooLarge(): void
    {
        $client = $this->client;

        $largeContent = str_repeat('a', 11 * 1024 * 1024);
        $tempFile = tempnam(sys_get_temp_dir(), 'doc_') . '.pdf';
        file_put_contents($tempFile, $largeContent);

        $file = new UploadedFile(
            $tempFile,
            'large.pdf',
            'application/pdf',
            null,
            true
        );

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/documents', [
            'documentType' => 'ANSWER_SHEET',
        ], [
            'file' => $file,
        ], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('file', $data['errors']);
        $this->assertStringContainsString('too large', strtolower($data['errors']['file'][0]));
    }

    public function testUploadRejectsNotStartedAttempt(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $attempt = $entityManager->getRepository(ExamAttempt::class)->find($this->testAttemptId);
        $attempt->setStatus(ExamAttemptStatus::NotStarted);
        $entityManager->flush();

        $file = $this->createTempPdf('not-started.pdf');
        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/documents', [
            'documentType' => 'ANSWER_SHEET',
        ], [
            'file' => $file,
        ], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('not allowed', strtolower($data['message']));

        $attempt->setStatus(ExamAttemptStatus::InProgress);
        $entityManager->flush();
    }
}
