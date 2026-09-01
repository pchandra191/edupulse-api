<?php
declare(strict_types=1);

namespace App\Tests\Controller\ExamAnswerModule;

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

class ExamAnswerControllerTest extends WebTestCase
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
    private ?Uuid $testQuestionId = null;
    private ?Uuid $testOptionId = null;
    private ?string $studentToken = null;
    private ?string $instituteToken = null;
    private ?string $otherInstituteToken = null;
    private ?string $superAdminToken = null;
    private ?Uuid $otherInstituteId = null;

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
        $this->testQuestionId = Uuid::v7();
        $this->testOptionId = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-exam-answer-test-' . $this->unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for Exam Answers');
        $institute->setCode('TESTEA' . $this->unique);
        $institute->setEmail('test-institute-exam-answer-' . $this->unique . '@edupulse.ai');
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
        $student->setEmail('student-exam-answer-test-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($institute);

        $instituteUser = new User();
        $instituteUser->setEmail('institute-exam-answer-test-' . $this->unique . '@edupulse.ai');
        $instituteUser->setPassword(password_hash('Institute123!', PASSWORD_BCRYPT));
        $instituteUser->setRoles([UserRole::Institute->value]);
        $instituteUser->setStatus(UserStatus::Active);
        $instituteUser->verify();
        $instituteUser->setInstitute($institute);

        $otherInstitute = new Institute();
        $otherInstituteReflection = new \ReflectionClass($otherInstitute);
        $otherInstituteProperty = $otherInstituteReflection->getProperty('id');
        $otherInstituteProperty->setAccessible(true);
        $otherInstituteProperty->setValue($otherInstitute, Uuid::v7());
        $this->otherInstituteId = $otherInstituteProperty->getValue($otherInstitute);
        $otherInstitute->setName('Other Test Institute for Exam Answers');
        $otherInstitute->setCode('OTHEA' . $this->unique);
        $otherInstitute->setEmail('other-institute-exam-answer-' . $this->unique . '@edupulse.ai');
        $otherInstitute->setStatus(InstituteStatus::Active);

        $otherInstituteUser = new User();
        $otherInstituteUser->setEmail('other-institute-exam-answer-test-' . $this->unique . '@edupulse.ai');
        $otherInstituteUser->setPassword(password_hash('Institute123!', PASSWORD_BCRYPT));
        $otherInstituteUser->setRoles([UserRole::Institute->value]);
        $otherInstituteUser->setStatus(UserStatus::Active);
        $otherInstituteUser->verify();
        $otherInstituteUser->setInstitute($otherInstitute);

        $attempt = new ExamAttempt();
        $attemptId = Uuid::v7();
        $attemptReflection = new \ReflectionClass($attempt);
        $attemptProperty = $attemptReflection->getProperty('id');
        $attemptProperty->setAccessible(true);
        $attemptProperty->setValue($attempt, $attemptId);
        $attempt->setExamSession($examSession);
        $attempt->setStudent($student);
        $attempt->setStatus(ExamAttemptStatus::InProgress);
        $attempt->setStartedAt(new \DateTimeImmutable());

        $this->testAttemptId = $attemptId;

        $question = new Question();
        $qReflection = new \ReflectionClass($question);
        $qProperty = $qReflection->getProperty('id');
        $qProperty->setAccessible(true);
        $qProperty->setValue($question, $this->testQuestionId);
        $question->setQuestionPaper($questionPaper);
        $question->setType(QuestionType::MCQ);
        $question->setQuestionText('What is 2+2?');
        $question->setMarks(5);
        $question->setDisplayOrder(1);

        $option = new QuestionOption();
        $oReflection = new \ReflectionClass($option);
        $oProperty = $oReflection->getProperty('id');
        $oProperty->setAccessible(true);
        $oProperty->setValue($option, $this->testOptionId);
        $option->setQuestion($question);
        $option->setOptionText('4');
        $option->setDisplayOrder(1);
        $option->setIsCorrect(true);

        $question->addOption($option);

        $entityManager->persist($superAdmin);
        $entityManager->persist($institute);
        $entityManager->persist($academicYear);
        $entityManager->persist($academicClass);
        $entityManager->persist($section);
        $entityManager->persist($subject);
        $entityManager->persist($questionPaper);
        $entityManager->persist($examSession);
        $entityManager->persist($student);
        $entityManager->persist($instituteUser);
        $entityManager->persist($otherInstitute);
        $entityManager->persist($otherInstituteUser);
        $entityManager->persist($attempt);
        $entityManager->persist($question);
        $entityManager->persist($option);
        $entityManager->flush();

        $this->seedTestPermissions($entityManager);
        $entityManager->flush();

        $this->createdEmails = [
            'superadmin-exam-answer-test-' . $this->unique . '@edupulse.ai',
            'student-exam-answer-test-' . $this->unique . '@edupulse.ai',
            'institute-exam-answer-test-' . $this->unique . '@edupulse.ai',
            'other-institute-exam-answer-test-' . $this->unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login($this->client, 'superadmin-exam-answer-test-' . $this->unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->studentToken = $this->login($this->client, 'student-exam-answer-test-' . $this->unique . '@edupulse.ai', 'Student123!');
        $this->instituteToken = $this->login($this->client, 'institute-exam-answer-test-' . $this->unique . '@edupulse.ai', 'Institute123!');
        $this->otherInstituteToken = $this->login($this->client, 'other-institute-exam-answer-test-' . $this->unique . '@edupulse.ai', 'Institute123!');
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

            if ($this->otherInstituteId) {
                $otherInstitute = $instituteRepo->find($this->otherInstituteId);
                if ($otherInstitute) {
                    $entityManager->remove($otherInstitute);
                }
            }

            $entityManager->flush();
        } catch (\Throwable $e) {
            // Ignore cleanup errors
        }

        parent::tearDown();
    }

    private function seedTestPermissions($entityManager): void
    {
        $roleCodes = ['ROLE_INSTITUTE', 'ROLE_TEACHER'];
        $roles = [];
        $roleRepository = $entityManager->getRepository(\App\Entity\Role::class);

        foreach ($roleCodes as $roleCode) {
            $role = $roleRepository->findOneBy(['code' => $roleCode]);

            if (!$role) {
                $role = new \App\Entity\Role();
                $role->setCode($roleCode);
                $role->setName(ucwords(strtolower(str_replace('ROLE_', '', $roleCode))));
                $role->setDescription('Test role ' . $roleCode);
                $role->setStatus(\App\Enum\RoleStatus::Active);
                $entityManager->persist($role);
            }

            $roles[$roleCode] = $role;
        }

        $permissionCodes = [
            'exam_answers.view',
            'exam_answers.create',
        ];

        $permissions = [];
        $permissionRepository = $entityManager->getRepository(\App\Entity\Permission::class);

        foreach ($permissionCodes as $code) {
            $existing = $permissionRepository->findOneBy(['code' => $code]);

            if ($existing) {
                $permissions[$code] = $existing;
                continue;
            }

            $permission = new \App\Entity\Permission();
            $permission->setModule('exam-answers');
            $permission->setGroupName('Exam Answers');
            $permission->setCode($code);
            $permission->setName(ucwords(str_replace(['-', '_'], ' ', $code)));
            $permission->setDescription('Test permission for ' . $code);
            $entityManager->persist($permission);
            $permissions[$code] = $permission;
        }

        $rolePermissionRepository = $entityManager->getRepository(\App\Entity\RolePermission::class);

        foreach ($roles as $role) {
            foreach ($permissions as $permission) {
                $existing = $rolePermissionRepository->findOneBy(['role' => $role, 'permission' => $permission]);

                if (!$existing) {
                    $rolePermission = new \App\Entity\RolePermission();
                    $rolePermission->setRole($role);
                    $rolePermission->setPermission($permission);
                    $entityManager->persist($rolePermission);
                }
            }
        }
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

    public function testStudentCanSaveMcqAnswer(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionId' => (string) $this->testQuestionId,
            'selectedOptionId' => (string) $this->testOptionId,
        ]);

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->studentToken), $payload);

        echo "Status: " . $client->getResponse()->getStatusCode() . "\n";
        echo "Content: " . $client->getResponse()->getContent() . "\n";

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame((string) $this->testQuestionId, $data['data']['questionId']);
        $this->assertSame((string) $this->testOptionId, $data['data']['selectedOptionId']);
        $this->assertNull($data['data']['answerText']);
    }

    public function testStudentCanUpdateAnswer(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionId' => (string) $this->testQuestionId,
            'selectedOptionId' => (string) $this->testOptionId,
        ]);

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->studentToken), $payload);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->studentToken), $payload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testStudentCannotAnswerSubmittedAttempt(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $attempt = $entityManager->getRepository(ExamAttempt::class)->find($this->testAttemptId);
        $attempt->setStatus(\App\Enum\ExamAttemptStatus::Submitted);
        $entityManager->flush();

        $payload = json_encode([
            'questionId' => (string) $this->testQuestionId,
            'selectedOptionId' => (string) $this->testOptionId,
        ]);

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->studentToken), $payload);

        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('in-progress', strtolower($data['message']));
    }

    public function testStudentCanAnswerOwnAttempt(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherStudent = new User();
        $otherStudent->setEmail('other-student-exam-answer-' . $this->unique . '@edupulse.ai');
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();
        $otherStudent->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $otherAttempt = new ExamAttempt();
        $otherAttempt->setExamSession($entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId));
        $otherAttempt->setStudent($otherStudent);
        $otherAttempt->setStatus(ExamAttemptStatus::InProgress);
        $otherAttempt->setStartedAt(new \DateTimeImmutable());

        $entityManager->persist($otherStudent);
        $entityManager->persist($otherAttempt);
        $entityManager->flush();

        $otherToken = $this->login($client, 'other-student-exam-answer-' . $this->unique . '@edupulse.ai', 'Student123!');

        $payload = json_encode([
            'questionId' => (string) $this->testQuestionId,
            'selectedOptionId' => (string) $this->testOptionId,
        ]);

        $client->request('POST', '/api/v1/exam-attempts/' . $otherAttempt->getId() . '/answers', [], [], $this->getAuthHeaders($otherToken), $payload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        $otherAttempt = $entityManager->getRepository(ExamAttempt::class)->find($otherAttempt->getId());
        $otherStudent = $entityManager->getRepository(User::class)->findOneBy(['email' => 'other-student-exam-answer-' . $this->unique . '@edupulse.ai']);
        if ($otherAttempt) {
            $entityManager->remove($otherAttempt);
        }
        if ($otherStudent) {
            $entityManager->remove($otherStudent);
        }
        $entityManager->flush();
    }

    public function testStudentCannotAnswerAnotherStudentsAttempt(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherStudent = new User();
        $otherStudent->setEmail('other-student-exam-answer-' . $this->unique . '@edupulse.ai');
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();
        $otherStudent->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $otherAttempt = new ExamAttempt();
        $otherAttempt->setExamSession($entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId));
        $otherAttempt->setStudent($otherStudent);
        $otherAttempt->setStatus(ExamAttemptStatus::InProgress);
        $otherAttempt->setStartedAt(new \DateTimeImmutable());

        $entityManager->persist($otherStudent);
        $entityManager->persist($otherAttempt);
        $entityManager->flush();

        $otherToken = $this->login($client, 'other-student-exam-answer-' . $this->unique . '@edupulse.ai', 'Student123!');

        $payload = json_encode([
            'questionId' => (string) $this->testQuestionId,
            'selectedOptionId' => (string) $this->testOptionId,
        ]);

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($otherToken), $payload);

        $this->assertSame(404, $client->getResponse()->getStatusCode());

        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        $otherAttempt = $entityManager->getRepository(ExamAttempt::class)->find($otherAttempt->getId());
        $otherStudent = $entityManager->getRepository(User::class)->findOneBy(['email' => 'other-student-exam-answer-' . $this->unique . '@edupulse.ai']);
        if ($otherAttempt) {
            $entityManager->remove($otherAttempt);
        }
        if ($otherStudent) {
            $entityManager->remove($otherStudent);
        }
        $entityManager->flush();
    }

    public function testStudentCannotViewAnotherStudentsAnswers(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherStudent = new User();
        $otherStudent->setEmail('other-student-exam-answer-' . $this->unique . '@edupulse.ai');
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();
        $otherStudent->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $otherAttempt = new ExamAttempt();
        $otherAttempt->setExamSession($entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId));
        $otherAttempt->setStudent($otherStudent);
        $otherAttempt->setStatus(ExamAttemptStatus::InProgress);
        $otherAttempt->setStartedAt(new \DateTimeImmutable());

        $entityManager->persist($otherStudent);
        $entityManager->persist($otherAttempt);
        $entityManager->flush();

        $otherToken = $this->login($client, 'other-student-exam-answer-' . $this->unique . '@edupulse.ai', 'Student123!');

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($otherToken));

        $this->assertSame(404, $client->getResponse()->getStatusCode());

        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        $otherAttempt = $entityManager->getRepository(ExamAttempt::class)->find($otherAttempt->getId());
        $otherStudent = $entityManager->getRepository(User::class)->findOneBy(['email' => 'other-student-exam-answer-' . $this->unique . '@edupulse.ai']);
        if ($otherAttempt) {
            $entityManager->remove($otherAttempt);
        }
        if ($otherStudent) {
            $entityManager->remove($otherStudent);
        }
        $entityManager->flush();
    }

    public function testQuestionFromAnotherPaperIsRejected(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherPaper = new QuestionPaper();
        $otherPaperId = Uuid::v7();
        $qpReflection = new \ReflectionClass($otherPaper);
        $qpProperty = $qpReflection->getProperty('id');
        $qpProperty->setAccessible(true);
        $qpProperty->setValue($otherPaper, $otherPaperId);
        $otherPaper->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $otherPaper->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $otherPaper->setSubject($entityManager->getRepository(Subject::class)->find($this->testSubjectId));
        $otherPaper->setTitle('Other Paper ' . $this->unique);
        $otherPaper->setDate(new \DateTimeImmutable('2025-05-01'));
        $otherPaper->setDuration(60);
        $otherPaper->setMaximumMarks(100);
        $otherPaper->setStatus(QuestionPaperStatus::Finalized);

        $otherQuestion = new Question();
        $otherQuestionId = Uuid::v7();
        $qReflection = new \ReflectionClass($otherQuestion);
        $qProperty = $qReflection->getProperty('id');
        $qProperty->setAccessible(true);
        $qProperty->setValue($otherQuestion, $otherQuestionId);
        $otherQuestion->setQuestionPaper($otherPaper);
        $otherQuestion->setType(QuestionType::MCQ);
        $otherQuestion->setQuestionText('Wrong paper question');
        $otherQuestion->setMarks(5);
        $otherQuestion->setDisplayOrder(1);

        $entityManager->persist($otherPaper);
        $entityManager->persist($otherQuestion);
        $entityManager->flush();

        $payload = json_encode([
            'questionId' => (string) $otherQuestionId,
            'selectedOptionId' => (string) $this->testOptionId,
        ]);

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->studentToken), $payload);

        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('does not belong to this exam session', strtolower($data['message']));
    }

    public function testOptionFromAnotherQuestionIsRejected(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherQuestion = new Question();
        $otherQuestionId = Uuid::v7();
        $qReflection = new \ReflectionClass($otherQuestion);
        $qProperty = $qReflection->getProperty('id');
        $qProperty->setAccessible(true);
        $qProperty->setValue($otherQuestion, $otherQuestionId);
        $otherQuestion->setQuestionPaper($entityManager->getRepository(\App\Entity\QuestionPaper::class)->find($this->testQuestionPaperId));
        $otherQuestion->setType(\App\Enum\QuestionType::MCQ);
        $otherQuestion->setQuestionText('Another question');
        $otherQuestion->setMarks(1);
        $otherQuestion->setDisplayOrder(2);

        $wrongOption = new QuestionOption();
        $wrongOptionId = Uuid::v7();
        $oReflection = new \ReflectionClass($wrongOption);
        $oProperty = $oReflection->getProperty('id');
        $oProperty->setAccessible(true);
        $oProperty->setValue($wrongOption, $wrongOptionId);
        $wrongOption->setQuestion($otherQuestion);
        $wrongOption->setOptionText('Wrong option');
        $wrongOption->setDisplayOrder(1);
        $wrongOption->setIsCorrect(false);

        $entityManager->persist($otherQuestion);
        $entityManager->persist($wrongOption);
        $entityManager->flush();

        $payload = json_encode([
            'questionId' => (string) $this->testQuestionId,
            'selectedOptionId' => (string) $wrongOptionId,
        ]);

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->studentToken), $payload);

        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('does not belong to this question', strtolower($data['message']));
    }

    public function testGetAnswersReturnsOnlyOwnAnswers(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionId' => (string) $this->testQuestionId,
            'selectedOptionId' => (string) $this->testOptionId,
        ]);

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->studentToken), $payload);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $postData = json_decode($client->getResponse()->getContent(), true);
        $expectedQuestionId = $postData['data']['questionId'];

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('answers', $data['data']);
        $this->assertCount(1, $data['data']['answers']);
        $this->assertSame($expectedQuestionId, $data['data']['answers'][0]['answer']['questionId']);
    }

    public function testStudentResponseDoesNotExposeIsCorrect(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionId' => (string) $this->testQuestionId,
            'selectedOptionId' => (string) $this->testOptionId,
        ]);

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->studentToken), $payload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $responseContent = $client->getResponse()->getContent();
        $this->assertStringNotContainsString('isCorrect', $responseContent);
    }

    public function testOrganizerCanViewAnswersForSubmittedAttempt(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionId' => (string) $this->testQuestionId,
            'selectedOptionId' => (string) $this->testOptionId,
        ]);

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->studentToken), $payload);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        $attempt = $entityManager->getRepository(ExamAttempt::class)->find($this->testAttemptId);
        $attempt->setStatus(ExamAttemptStatus::Submitted);
        $attempt->setSubmittedAt(new \DateTimeImmutable());
        $entityManager->flush();

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertCount(1, $data['data']['items']);
        $this->assertSame((string) $this->testQuestionId, $data['data']['items'][0]['questionId']);
        $this->assertTrue($data['data']['items'][0]['isCorrect']);
        $this->assertSame(5, $data['data']['items'][0]['marksObtained']);
        $this->assertSame((string) $this->testOptionId, $data['data']['items'][0]['correctOptionId']);
    }

    public function testOrganizerCanViewAnswersForEvaluatedAttempt(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionId' => (string) $this->testQuestionId,
            'selectedOptionId' => (string) $this->testOptionId,
        ]);

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->studentToken), $payload);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        $attempt = $entityManager->getRepository(ExamAttempt::class)->find($this->testAttemptId);
        $attempt->setStatus(ExamAttemptStatus::Evaluated);
        $attempt->setScore(5);
        $attempt->setMaxMarks(5);
        $attempt->setPercentage(100);
        $entityManager->flush();

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertCount(1, $data['data']['items']);
        $this->assertTrue($data['data']['items'][0]['isCorrect']);
    }

    public function testOrganizerFromAnotherInstituteCannotViewAnswers(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionId' => (string) $this->testQuestionId,
            'selectedOptionId' => (string) $this->testOptionId,
        ]);

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->studentToken), $payload);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->otherInstituteToken));

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testOrganizerCanViewEmptyAnswers(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertCount(0, $data['data']['items']);
    }

    public function testNonExistentAttemptReturns404ForOrganizer(): void
    {
        $client = $this->client;

        $nonExistentId = Uuid::v7();

        $client->request('GET', '/api/v1/exam-attempts/' . $nonExistentId . '/answers', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testOrganizerSeesMcqSelectedAnswerCorrectly(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'questionId' => (string) $this->testQuestionId,
            'selectedOptionId' => (string) $this->testOptionId,
        ]);

        $client->request('POST', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->studentToken), $payload);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertCount(1, $data['data']['items']);
        $item = $data['data']['items'][0];
        $this->assertSame((string) $this->testOptionId, $item['selectedOptionId']);
        $this->assertSame('4', $item['selectedOptionText']);
        $this->assertTrue($item['isCorrect']);
        $this->assertSame(5, $item['marksObtained']);
        $this->assertSame((string) $this->testQuestionId, $item['questionId']);
        $this->assertSame('What is 2+2?', $item['questionText']);
        $this->assertSame('MCQ', $item['questionType']);
    }

    public function testOrganizerSeesDescriptiveAnswerAsPendingNotIncorrect(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $paper = $entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId);
        $subject = $entityManager->getRepository(Subject::class)->findOneBy([]);

        $descriptiveQuestion = new Question();
        $descQId = \Symfony\Component\Uid\Uuid::v7();
        $qRef = new \ReflectionClass($descriptiveQuestion);
        $qProp = $qRef->getProperty('id'); $qProp->setAccessible(true); $qProp->setValue($descriptiveQuestion, $descQId);
        $descriptiveQuestion->setQuestionPaper($paper);
        $descriptiveQuestion->setType(\App\Enum\QuestionType::Descriptive);
        $descriptiveQuestion->setQuestionText('Explain photosynthesis.');
        $descriptiveQuestion->setMarks(10);
        $descriptiveQuestion->setDisplayOrder(2);
        $descriptiveQuestion->setReferenceAnswer('Plants use sunlight to make food from CO2 and water.');

        $entityManager->persist($descriptiveQuestion);
        $entityManager->flush();

        $attempt = $entityManager->getRepository(ExamAttempt::class)->find($this->testAttemptId);
        $attempt->setStatus(ExamAttemptStatus::Submitted);
        $attempt->setSubmittedAt(new \DateTimeImmutable());
        $entityManager->flush();

        $answer = new ExamAnswer();
        $answerRef = new \ReflectionClass($answer);
        $answerProp = $answerRef->getProperty('id'); $answerProp->setAccessible(true); $answerProp->setValue($answer, \Symfony\Component\Uid\Uuid::v7());
        $answer->setExamAttempt($attempt);
        $answer->setQuestion($descriptiveQuestion);
        $answer->setAnswerText('Plants use sunlight to make food.');
        $entityManager->persist($answer);
        $entityManager->flush();

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);

        $descriptive = null;
        foreach ($data['data']['items'] as $item) {
            if ($item['questionId'] === (string) $descQId) {
                $descriptive = $item;
                break;
            }
        }
        $this->assertNotNull($descriptive, 'Descriptive question should appear in organizer response');
        $this->assertSame('DESCRIPTIVE', $descriptive['questionType']);
        $this->assertSame('Plants use sunlight to make food from CO2 and water.', $descriptive['referenceAnswer']);
        $this->assertNull($descriptive['isCorrect'], 'Descriptive without evaluation should have isCorrect=null');
        $this->assertNull($descriptive['marksObtained'], 'Descriptive without evaluation should have marksObtained=null');
        $this->assertTrue($descriptive['requiresManualEvaluation']);
    }

    public function testOrganizerSeesDescriptiveWithoutReferenceAnswerAsPending(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $paper = $entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId);

        $descriptiveQuestion = new Question();
        $descQId = \Symfony\Component\Uid\Uuid::v7();
        $qRef = new \ReflectionClass($descriptiveQuestion);
        $qProp = $qRef->getProperty('id'); $qProp->setAccessible(true); $qProp->setValue($descriptiveQuestion, $descQId);
        $descriptiveQuestion->setQuestionPaper($paper);
        $descriptiveQuestion->setType(\App\Enum\QuestionType::Descriptive);
        $descriptiveQuestion->setQuestionText('Explain something.');
        $descriptiveQuestion->setMarks(5);
        $descriptiveQuestion->setDisplayOrder(2);
        // No reference answer set
        $entityManager->persist($descriptiveQuestion);
        $entityManager->flush();

        $attempt = $entityManager->getRepository(ExamAttempt::class)->find($this->testAttemptId);
        $attempt->setStatus(ExamAttemptStatus::Submitted);
        $attempt->setSubmittedAt(new \DateTimeImmutable());
        $entityManager->flush();

        $answer = new ExamAnswer();
        $answerRef = new \ReflectionClass($answer);
        $answerProp = $answerRef->getProperty('id'); $answerProp->setAccessible(true); $answerProp->setValue($answer, \Symfony\Component\Uid\Uuid::v7());
        $answer->setExamAttempt($attempt);
        $answer->setQuestion($descriptiveQuestion);
        $answer->setAnswerText('Some answer text');
        $entityManager->persist($answer);
        $entityManager->flush();

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);

        $descriptive = null;
        foreach ($data['data']['items'] as $item) {
            if ($item['questionId'] === (string) $descQId) {
                $descriptive = $item;
                break;
            }
        }
        $this->assertNotNull($descriptive);
        $this->assertNull($descriptive['referenceAnswer']);
        $this->assertNull($descriptive['isCorrect'], 'Missing reference answer MUST NOT be marked incorrect');
        $this->assertNull($descriptive['marksObtained'], 'Missing reference answer MUST NOT be marked as 0 marks');
        $this->assertTrue($descriptive['requiresManualEvaluation']);
    }

    public function testOrganizerSeesMcqWithoutCorrectOptionAsPending(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $paper = $entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId);

        $mcqQuestion = new Question();
        $mcqId = \Symfony\Component\Uid\Uuid::v7();
        $qRef = new \ReflectionClass($mcqQuestion);
        $qProp = $qRef->getProperty('id'); $qProp->setAccessible(true); $qProp->setValue($mcqQuestion, $mcqId);
        $mcqQuestion->setQuestionPaper($paper);
        $mcqQuestion->setType(\App\Enum\QuestionType::MCQ);
        $mcqQuestion->setQuestionText('What is the meaning of life?');
        $mcqQuestion->setMarks(5);
        $mcqQuestion->setDisplayOrder(2);

        $entityManager->persist($mcqQuestion);
        $entityManager->flush();

        $opt1 = new QuestionOption();
        $opt1Ref = new \ReflectionClass($opt1);
        $opt1Prop = $opt1Ref->getProperty('id'); $opt1Prop->setAccessible(true); $opt1Prop->setValue($opt1, \Symfony\Component\Uid\Uuid::v7());
        $opt1->setQuestion($mcqQuestion);
        $opt1->setOptionText('42');
        $opt1->setDisplayOrder(1);
        $opt1->setIsCorrect(false);
        $entityManager->persist($opt1);

        $entityManager->flush();

        $attempt = $entityManager->getRepository(ExamAttempt::class)->find($this->testAttemptId);
        $attempt->setStatus(ExamAttemptStatus::Submitted);
        $attempt->setSubmittedAt(new \DateTimeImmutable());
        $entityManager->flush();

        $answer = new ExamAnswer();
        $answerRef = new \ReflectionClass($answer);
        $answerProp = $answerRef->getProperty('id'); $answerProp->setAccessible(true); $answerProp->setValue($answer, \Symfony\Component\Uid\Uuid::v7());
        $answer->setExamAttempt($attempt);
        $answer->setQuestion($mcqQuestion);
        $answer->setSelectedOption($opt1);
        $entityManager->persist($answer);
        $entityManager->flush();

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);

        $mcq = null;
        foreach ($data['data']['items'] as $item) {
            if ($item['questionId'] === (string) $mcqId) {
                $mcq = $item;
                break;
            }
        }
        $this->assertNotNull($mcq);
        $this->assertNull($mcq['correctOptionId'], 'MCQ without correct option should have null correctOptionId');
        $this->assertNull($mcq['isCorrect'], 'MCQ without correct option MUST NOT be marked incorrect');
        $this->assertNull($mcq['marksObtained'], 'MCQ without correct option MUST NOT have 0 marks awarded');
        $this->assertTrue($mcq['requiresManualEvaluation']);
    }

    public function testStudentResponseDoesNotExposeReferenceAnswer(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $paper = $entityManager->getRepository(QuestionPaper::class)->find($this->testQuestionPaperId);

        $descriptiveQuestion = new Question();
        $descQId = \Symfony\Component\Uid\Uuid::v7();
        $qRef = new \ReflectionClass($descriptiveQuestion);
        $qProp = $qRef->getProperty('id'); $qProp->setAccessible(true); $qProp->setValue($descriptiveQuestion, $descQId);
        $descriptiveQuestion->setQuestionPaper($paper);
        $descriptiveQuestion->setType(\App\Enum\QuestionType::Descriptive);
        $descriptiveQuestion->setQuestionText('Explain something.');
        $descriptiveQuestion->setMarks(5);
        $descriptiveQuestion->setDisplayOrder(2);
        $descriptiveQuestion->setReferenceAnswer('SECRET reference - should never leak to students');
        $entityManager->persist($descriptiveQuestion);
        $entityManager->flush();

        $answer = new ExamAnswer();
        $answerRef = new \ReflectionClass($answer);
        $answerProp = $answerRef->getProperty('id'); $answerProp->setAccessible(true); $answerProp->setValue($answer, \Symfony\Component\Uid\Uuid::v7());
        $answer->setExamAttempt($entityManager->getRepository(ExamAttempt::class)->find($this->testAttemptId));
        $answer->setQuestion($descriptiveQuestion);
        $answer->setAnswerText('My answer');
        $entityManager->persist($answer);
        $entityManager->flush();

        $client->request('GET', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers', [], [], $this->getAuthHeaders($this->studentToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $content = $client->getResponse()->getContent();
        $this->assertStringNotContainsString('referenceAnswer', $content);
        $this->assertStringNotContainsString('SECRET reference', $content);
        $this->assertStringNotContainsString('correctOptionId', $content);
        $this->assertStringNotContainsString('isCorrect', $content);
    }
}
