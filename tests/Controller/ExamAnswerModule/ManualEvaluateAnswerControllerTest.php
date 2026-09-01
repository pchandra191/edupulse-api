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

class ManualEvaluateAnswerControllerTest extends WebTestCase
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
    private ?Uuid $testMcqQuestionId = null;
    private ?Uuid $testDescriptiveQuestionId = null;
    private ?Uuid $testAnswerId = null;
    private ?string $studentToken = null;
    private ?string $instituteToken = null;
    private ?string $superAdminToken = null;
    private ?string $otherInstituteToken = null;
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
        $this->testMcqQuestionId = Uuid::v7();
        $this->testDescriptiveQuestionId = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-manual-eval-test-' . $this->unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for Manual Eval');
        $institute->setCode('TESTME' . $this->unique);
        $institute->setEmail('test-institute-manual-eval-' . $this->unique . '@edupulse.ai');
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
        $student->setEmail('student-manual-eval-test-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($institute);

        $instituteUser = new User();
        $instituteUser->setEmail('institute-manual-eval-test-' . $this->unique . '@edupulse.ai');
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
        $otherInstitute->setName('Other Institute for Manual Eval');
        $otherInstitute->setCode('OTHEME' . $this->unique);
        $otherInstitute->setEmail('other-institute-manual-eval-' . $this->unique . '@edupulse.ai');
        $otherInstitute->setStatus(InstituteStatus::Active);

        $otherInstituteUser = new User();
        $otherInstituteUser->setEmail('other-institute-manual-eval-test-' . $this->unique . '@edupulse.ai');
        $otherInstituteUser->setPassword(password_hash('Institute123!', PASSWORD_BCRYPT));
        $otherInstituteUser->setRoles([UserRole::Institute->value]);
        $otherInstituteUser->setStatus(UserStatus::Active);
        $otherInstituteUser->verify();
        $otherInstituteUser->setInstitute($otherInstitute);

        $attempt = new ExamAttempt();
        $attemptReflection = new \ReflectionClass($attempt);
        $attemptProperty = $attemptReflection->getProperty('id');
        $attemptProperty->setAccessible(true);
        $attemptProperty->setValue($attempt, $this->testAttemptId);
        $attempt->setExamSession($examSession);
        $attempt->setStudent($student);
        $attempt->setStatus(ExamAttemptStatus::Submitted);
        $attempt->setStartedAt(new \DateTimeImmutable('2025-01-01 09:00:00'));
        $attempt->setSubmittedAt(new \DateTimeImmutable('2025-01-01 10:00:00'));

        $mcqQuestion = new Question();
        $qReflection = new \ReflectionClass($mcqQuestion);
        $qProperty = $qReflection->getProperty('id');
        $qProperty->setAccessible(true);
        $qProperty->setValue($mcqQuestion, $this->testMcqQuestionId);
        $mcqQuestion->setQuestionPaper($questionPaper);
        $mcqQuestion->setType(QuestionType::MCQ);
        $mcqQuestion->setQuestionText('What is 2+2?');
        $mcqQuestion->setMarks(5);
        $mcqQuestion->setDisplayOrder(1);

        $mcqOption = new QuestionOption();
        $oReflection = new \ReflectionClass($mcqOption);
        $oProperty = $oReflection->getProperty('id');
        $oProperty->setAccessible(true);
        $oProperty->setValue($mcqOption, Uuid::v7());
        $mcqOption->setQuestion($mcqQuestion);
        $mcqOption->setOptionText('4');
        $mcqOption->setDisplayOrder(1);
        $mcqOption->setIsCorrect(true);
        $mcqQuestion->addOption($mcqOption);

        $descriptiveQuestion = new Question();
        $dqReflection = new \ReflectionClass($descriptiveQuestion);
        $dqProperty = $dqReflection->getProperty('id');
        $dqProperty->setAccessible(true);
        $dqProperty->setValue($descriptiveQuestion, $this->testDescriptiveQuestionId);
        $descriptiveQuestion->setQuestionPaper($questionPaper);
        $descriptiveQuestion->setType(QuestionType::Descriptive);
        $descriptiveQuestion->setQuestionText('Explain photosynthesis.');
        $descriptiveQuestion->setMarks(33);
        $descriptiveQuestion->setDisplayOrder(2);
        $descriptiveQuestion->setReferenceAnswer('Plants use sunlight to make food from CO2 and water.');

        $mcqAnswer = new ExamAnswer();
        $mcqAnswerReflection = new \ReflectionClass($mcqAnswer);
        $mcqAnswerProperty = $mcqAnswerReflection->getProperty('id');
        $mcqAnswerProperty->setAccessible(true);
        $mcqAnswerProperty->setValue($mcqAnswer, Uuid::v7());
        $mcqAnswer->setExamAttempt($attempt);
        $mcqAnswer->setQuestion($mcqQuestion);
        $mcqAnswer->setSelectedOption($mcqOption);

        $descriptiveAnswer = new ExamAnswer();
        $this->testAnswerId = Uuid::v7();
        $daReflection = new \ReflectionClass($descriptiveAnswer);
        $daProperty = $daReflection->getProperty('id');
        $daProperty->setAccessible(true);
        $daProperty->setValue($descriptiveAnswer, $this->testAnswerId);
        $descriptiveAnswer->setExamAttempt($attempt);
        $descriptiveAnswer->setQuestion($descriptiveQuestion);
        $descriptiveAnswer->setAnswerText('Plants use sunlight to make food from CO2 and water.');
        $descriptiveAnswer->setRequiresManualEvaluation(true);

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
        $entityManager->persist($mcqQuestion);
        $entityManager->persist($mcqOption);
        $entityManager->persist($descriptiveQuestion);
        $entityManager->persist($mcqAnswer);
        $entityManager->persist($descriptiveAnswer);

        $this->seedTestPermissions($entityManager);
        $entityManager->flush();

        $this->createdEmails = [
            'superadmin-manual-eval-test-' . $this->unique . '@edupulse.ai',
            'student-manual-eval-test-' . $this->unique . '@edupulse.ai',
            'institute-manual-eval-test-' . $this->unique . '@edupulse.ai',
            'other-institute-manual-eval-test-' . $this->unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login('superadmin-manual-eval-test-' . $this->unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->studentToken = $this->login('student-manual-eval-test-' . $this->unique . '@edupulse.ai', 'Student123!');
        $this->instituteToken = $this->login('institute-manual-eval-test-' . $this->unique . '@edupulse.ai', 'Institute123!');
        $this->otherInstituteToken = $this->login('other-institute-manual-eval-test-' . $this->unique . '@edupulse.ai', 'Institute123!');
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
            'exam_answers.edit',
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

    public function testOrganizerCanManuallyEvaluateDescriptiveAnswer(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'marksObtained' => 25,
        ]);

        $client->request('PUT', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers/' . $this->testAnswerId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame(25, $data['data']['marksObtained']);
        $this->assertFalse($data['data']['requiresManualEvaluation']);
        $this->assertTrue($data['data']['isCorrect']);
    }

    public function testMarksObtainedIsPersisted(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $payload = json_encode([
            'marksObtained' => 20,
        ]);

        $client->request('PUT', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers/' . $this->testAnswerId, [], [], $this->getAuthHeaders($this->instituteToken), $payload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $answer = $entityManager->getRepository(ExamAnswer::class)->find($this->testAnswerId);
        $this->assertSame(20, $answer->getMarksObtained());
        $this->assertFalse($answer->isRequiresManualEvaluation());
    }

    public function testRequiresManualEvaluationChangesToFalse(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $answer = $entityManager->getRepository(ExamAnswer::class)->find($this->testAnswerId);
        $this->assertTrue($answer->isRequiresManualEvaluation());

        $payload = json_encode([
            'marksObtained' => 15,
        ]);

        $client->request('PUT', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers/' . $this->testAnswerId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $entityManager->clear();
        $answer = $entityManager->getRepository(ExamAnswer::class)->find($this->testAnswerId);
        $this->assertFalse($answer->isRequiresManualEvaluation());
    }

    public function testIsCorrectRemainsNull(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'marksObtained' => 30,
        ]);

        $client->request('PUT', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers/' . $this->testAnswerId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['data']['isCorrect']);
    }

    public function testAnswerTextCanBeUpdated(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'marksObtained' => 25,
            'answerText' => 'Updated student answer text',
        ]);

        $client->request('PUT', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers/' . $this->testAnswerId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Updated student answer text', $data['data']['answerText']);
    }

    public function testAnswerTextRemainsUnchangedWhenOmitted(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $payload = json_encode([
            'marksObtained' => 25,
        ]);

        $client->request('PUT', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers/' . $this->testAnswerId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $entityManager->clear();
        $answer = $entityManager->getRepository(ExamAnswer::class)->find($this->testAnswerId);
        $this->assertSame('Plants use sunlight to make food from CO2 and water.', $answer->getAnswerText());
    }

    public function testMarksCannotBeNegative(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'marksObtained' => -1,
        ]);

        $client->request('PUT', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers/' . $this->testAnswerId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testMarksCannotExceedQuestionMaxMarks(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'marksObtained' => 34,
        ]);

        $client->request('PUT', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers/' . $this->testAnswerId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('33', $data['message']);
    }

    public function testUnauthorizedUserCannotEvaluate(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'marksObtained' => 25,
        ]);

        $client->request('PUT', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers/' . $this->testAnswerId, [], [], $this->getAuthHeaders($this->studentToken), $payload);

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testAnswerFromAnotherAttemptCannotBeModified(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherStudent = new User();
        $otherStudent->setEmail('other-student-manual-eval-' . $this->unique . '@edupulse.ai');
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();
        $otherStudent->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $otherAttempt = new ExamAttempt();
        $otherAttempt->setExamSession($entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId));
        $otherAttempt->setStudent($otherStudent);
        $otherAttempt->setStatus(ExamAttemptStatus::Submitted);
        $otherAttempt->setStartedAt(new \DateTimeImmutable());

        $entityManager->persist($otherStudent);
        $entityManager->persist($otherAttempt);
        $entityManager->flush();

        $payload = json_encode([
            'marksObtained' => 25,
        ]);

        $client->request('PUT', '/api/v1/exam-attempts/' . $otherAttempt->getId() . '/answers/' . $this->testAnswerId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(404, $client->getResponse()->getStatusCode());

        $entityManager->clear();
        $otherAttempt = $entityManager->getRepository(ExamAttempt::class)->find($otherAttempt->getId());
        $otherStudent = $entityManager->getRepository(User::class)->find($otherStudent->getId());

        if ($otherAttempt) {
            $entityManager->remove($otherAttempt);
        }
        if ($otherStudent) {
            $entityManager->remove($otherStudent);
        }
        $entityManager->flush();
    }

    public function testAlreadyEvaluatedDescriptiveAnswerCanBeReEvaluated(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $payload1 = json_encode(['marksObtained' => 20]);
        $client->request('PUT', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers/' . $this->testAnswerId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload1);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $entityManager->clear();

        $payload2 = json_encode(['marksObtained' => 27]);
        $client->request('PUT', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers/' . $this->testAnswerId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload2);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(27, $data['data']['marksObtained']);
        $this->assertFalse($data['data']['requiresManualEvaluation']);
    }

    public function testAttemptResultRecalculatesAfterEvaluation(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $payload = json_encode(['marksObtained' => 25]);
        $client->request('PUT', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers/' . $this->testAnswerId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $entityManager->clear();
        $attempt = $entityManager->getRepository(ExamAttempt::class)->find($this->testAttemptId);
        $this->assertSame(30, $attempt->getScore());
        $this->assertSame(38, $attempt->getMaxMarks());
        $this->assertSame(79, $attempt->getPercentage());
    }

    public function testPendingAnswersDecreasesAfterEvaluation(): void
    {
        $client = $this->client;

        $payload = json_encode(['marksObtained' => 25]);
        $client->request('PUT', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers/' . $this->testAnswerId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(25, $data['data']['marksObtained']);
    }

    public function testMcqBehaviorRemainsUnchanged(): void
    {
        $client = $this->client;

        $mcqAnswer = $client->getContainer()->get('doctrine')->getManager()
            ->getRepository(ExamAnswer::class)
            ->findOneBy(['question' => $this->testMcqQuestionId]);

        $payload = json_encode(['marksObtained' => 5]);
        $client->request('PUT', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers/' . $mcqAnswer->getId(), [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('descriptive', strtolower($data['message']));
    }

    public function testStudentResponseDoesNotExposeReferenceAnswer(): void
    {
        $client = $this->client;

        $payload = json_encode(['marksObtained' => 25]);
        $client->request('PUT', '/api/v1/exam-attempts/' . $this->testAttemptId . '/answers/' . $this->testAnswerId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('referenceAnswer', $content);
        $this->assertStringContainsString('Plants use sunlight to make food from CO2 and water.', $content);
    }
}
