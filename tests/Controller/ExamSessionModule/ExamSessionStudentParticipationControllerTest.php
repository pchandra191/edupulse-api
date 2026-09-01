<?php
declare(strict_types=1);

namespace App\Tests\Controller\ExamSessionModule;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\EnrollmentSection;
use App\Entity\ExamAttempt;
use App\Entity\ExamSession;
use App\Entity\Institute;
use App\Entity\QuestionPaper;
use App\Entity\Section;
use App\Entity\StudentEnrollment;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\EnrollmentSectionStatus;
use App\Enum\ExamAttemptStatus;
use App\Enum\ExamSessionStatus;
use App\Enum\InstituteStatus;
use App\Enum\QuestionPaperStatus;
use App\Enum\SectionStatus;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class ExamSessionStudentParticipationControllerTest extends WebTestCase
{
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private array $createdEmails = [];
    private string $unique = '';

    private ?string $superAdminToken = null;
    private ?string $instituteToken = null;
    private ?string $teacherToken = null;
    private ?string $studentToken = null;
    private ?string $otherInstituteToken = null;

    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
    private ?Uuid $testClassId = null;
    private ?Uuid $testSectionId = null;
    private ?Uuid $testOtherSectionId = null;
    private ?Uuid $testSubjectId = null;
    private ?Uuid $testQuestionPaperId = null;
    private ?Uuid $testExamSessionId = null;
    private ?Uuid $testStudentId = null;
    private ?Uuid $testOtherStudentId = null;
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
        $this->testOtherSectionId = Uuid::v7();
        $this->testSubjectId = Uuid::v7();
        $this->testQuestionPaperId = Uuid::v7();
        $this->testExamSessionId = Uuid::v7();
        $this->testStudentId = Uuid::v7();
        $this->testOtherStudentId = Uuid::v7();

        $superAdminUser = new User();
        $superAdminUser->setEmail('superadmin-participation-test-' . $this->unique . '@edupulse.ai');
        $superAdminUser->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdminUser->setRoles([UserRole::SuperAdmin->value]);
        $superAdminUser->setStatus(UserStatus::Active);
        $superAdminUser->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for Participation');
        $institute->setCode('TESTP' . $this->unique);
        $institute->setEmail('test-institute-participation-' . $this->unique . '@edupulse.ai');
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

        $otherSection = new Section();
        $otherSectionReflection = new \ReflectionClass($otherSection);
        $otherSectionProperty = $otherSectionReflection->getProperty('id');
        $otherSectionProperty->setAccessible(true);
        $otherSectionProperty->setValue($otherSection, $this->testOtherSectionId);
        $otherSection->setAcademicClass($academicClass);
        $otherSection->setName('Other Section ' . $this->unique);
        $otherSection->setCode('OSEC' . $this->unique);
        $otherSection->setStatus(SectionStatus::Active);

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
        $examSession->setCreatedBy($superAdminUser);

        $instituteUser = new User();
        $instituteUser->setEmail('institute-participation-test-' . $this->unique . '@edupulse.ai');
        $instituteUser->setPassword(password_hash('Institute123!', PASSWORD_BCRYPT));
        $instituteUser->setRoles([UserRole::Institute->value]);
        $instituteUser->setStatus(UserStatus::Active);
        $instituteUser->verify();
        $instituteUser->setInstitute($institute);

        $teacherUser = new User();
        $teacherUser->setEmail('teacher-participation-test-' . $this->unique . '@edupulse.ai');
        $teacherUser->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
        $teacherUser->setRoles([UserRole::Teacher->value]);
        $teacherUser->setStatus(UserStatus::Active);
        $teacherUser->verify();
        $teacherUser->setInstitute($institute);

        $student = new User();
        $student->setEmail('student-participation-test-' . $this->unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setFirstName('Rahul');
        $student->setLastName('Kumar');
        $student->setInstitute($institute);

        $otherStudent = new User();
        $otherStudent->setEmail('other-student-participation-test-' . $this->unique . '@edupulse.ai');
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();
        $otherStudent->setFirstName('Nisha');
        $otherStudent->setLastName('Chandra');
        $otherStudent->setInstitute($institute);

        $outsideStudent = new User();
        $outsideStudent->setEmail('outside-student-participation-test-' . $this->unique . '@edupulse.ai');
        $outsideStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $outsideStudent->setRoles([UserRole::Student->value]);
        $outsideStudent->setStatus(UserStatus::Active);
        $outsideStudent->verify();
        $outsideStudent->setFirstName('Outside');
        $outsideStudent->setLastName('Student');

        $otherInstitute = new Institute();
        $otherInstituteReflection = new \ReflectionClass($otherInstitute);
        $otherInstituteProperty = $otherInstituteReflection->getProperty('id');
        $otherInstituteProperty->setAccessible(true);
        $otherInstituteProperty->setValue($otherInstitute, Uuid::v7());
        $this->otherInstituteId = $otherInstituteProperty->getValue($otherInstitute);
        $otherInstitute->setName('Other Institute for Participation');
        $otherInstitute->setCode('OTP' . $this->unique);
        $otherInstitute->setEmail('other-institute-participation-' . $this->unique . '@edupulse.ai');
        $otherInstitute->setStatus(InstituteStatus::Active);

        $outsideStudent->setInstitute($otherInstitute);

        $entityManager->persist($institute);
        $entityManager->persist($academicYear);
        $entityManager->persist($academicClass);
        $entityManager->persist($section);
        $entityManager->persist($otherSection);
        $entityManager->persist($subject);
        $entityManager->persist($questionPaper);
        $entityManager->persist($examSession);
        $entityManager->persist($superAdminUser);
        $entityManager->persist($instituteUser);
        $entityManager->persist($teacherUser);
        $entityManager->persist($student);
        $entityManager->persist($otherStudent);
        $entityManager->persist($outsideStudent);
        $entityManager->persist($otherInstitute);

        $enrollment = new StudentEnrollment();
        $enrollment->setStudent($student);
        $enrollment->setAcademicYear($academicYear);
        $enrollment->setAcademicClass($academicClass);
        $enrollment->setInstituteId($institute->getId());
        $enrollment->setAdmissionNumber('ADM' . $this->unique);
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(StudentEnrollmentStatus::Active);

        $otherEnrollment = new StudentEnrollment();
        $otherEnrollment->setStudent($otherStudent);
        $otherEnrollment->setAcademicYear($academicYear);
        $otherEnrollment->setAcademicClass($academicClass);
        $otherEnrollment->setInstituteId($institute->getId());
        $otherEnrollment->setAdmissionNumber('ADM2' . $this->unique);
        $otherEnrollment->setIsCurrent(true);
        $otherEnrollment->setStatus(StudentEnrollmentStatus::Active);

        $outsideEnrollment = new StudentEnrollment();
        $outsideEnrollment->setStudent($outsideStudent);
        $outsideEnrollment->setAcademicYear($academicYear);
        $outsideEnrollment->setAcademicClass($academicClass);
        $outsideEnrollment->setInstituteId($otherInstitute->getId());
        $outsideEnrollment->setAdmissionNumber('ADM3' . $this->unique);
        $outsideEnrollment->setIsCurrent(true);
        $outsideEnrollment->setStatus(StudentEnrollmentStatus::Active);

        $enrollmentSection = new EnrollmentSection();
        $enrollmentSection->setStudentEnrollment($enrollment);
        $enrollmentSection->setSection($section);
        $enrollmentSection->setStatus(EnrollmentSectionStatus::Active);

        $otherEnrollmentSection = new EnrollmentSection();
        $otherEnrollmentSection->setStudentEnrollment($otherEnrollment);
        $otherEnrollmentSection->setSection($otherSection);
        $otherEnrollmentSection->setStatus(EnrollmentSectionStatus::Active);

        $entityManager->persist($enrollment);
        $entityManager->persist($otherEnrollment);
        $entityManager->persist($outsideEnrollment);
        $entityManager->persist($enrollmentSection);
        $entityManager->persist($otherEnrollmentSection);

        $this->seedTestPermissions($entityManager);

        $entityManager->flush();

        $this->createdEmails = [
            'superadmin-participation-test-' . $this->unique . '@edupulse.ai',
            'institute-participation-test-' . $this->unique . '@edupulse.ai',
            'teacher-participation-test-' . $this->unique . '@edupulse.ai',
            'student-participation-test-' . $this->unique . '@edupulse.ai',
            'other-student-participation-test-' . $this->unique . '@edupulse.ai',
            'outside-student-participation-test-' . $this->unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login($this->client, 'superadmin-participation-test-' . $this->unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->instituteToken = $this->login($this->client, 'institute-participation-test-' . $this->unique . '@edupulse.ai', 'Institute123!');
        $this->teacherToken = $this->login($this->client, 'teacher-participation-test-' . $this->unique . '@edupulse.ai', 'Teacher123!');
        $this->studentToken = $this->login($this->client, 'student-participation-test-' . $this->unique . '@edupulse.ai', 'Student123!');
        $this->otherInstituteToken = $this->login($this->client, 'outside-student-participation-test-' . $this->unique . '@edupulse.ai', 'Student123!');
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
            'exam_attempts.view',
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

    public function testSuperAdminCanViewStudentParticipation(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/student-participation', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertArrayHasKey('summary', $data['data']);
        $this->assertArrayHasKey('pagination', $data['data']);
        $this->assertSame(2, $data['data']['summary']['eligible']);
        $this->assertSame(2, $data['data']['summary']['notAttempted']);
    }

    public function testInstituteCanViewStudentParticipation(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/student-participation', [], [], $this->getAuthHeaders($this->instituteToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame(2, $data['data']['summary']['eligible']);
    }

    public function testTeacherCanViewStudentParticipation(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/student-participation', [], [], $this->getAuthHeaders($this->teacherToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame(2, $data['data']['summary']['eligible']);
    }

    public function testStudentCannotViewParticipation(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/student-participation', [], [], $this->getAuthHeaders($this->studentToken));

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testOtherInstituteCannotViewParticipation(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/student-participation', [], [], $this->getAuthHeaders($this->otherInstituteToken));

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testNonExistentSessionReturns404(): void
    {
        $client = $this->client;

        $nonExistentId = Uuid::v7();

        $client->request('GET', '/api/v1/exam-sessions/' . $nonExistentId . '/student-participation', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testStudentFromOtherClassIsExcluded(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherClass = new AcademicClass();
        $otherClassReflection = new \ReflectionClass($otherClass);
        $otherClassProperty = $otherClassReflection->getProperty('id');
        $otherClassProperty->setAccessible(true);
        $otherClassProperty->setValue($otherClass, Uuid::v7());
        $otherClass->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $otherClass->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $otherClass->setName('Other Class ' . $this->unique);
        $otherClass->setCode('OCLS' . $this->unique);
        $otherClass->setStatus(ClassStatus::Active);

        $otherClassStudent = new User();
        $otherClassStudent->setEmail('other-class-student-participation-test-' . $this->unique . '@edupulse.ai');
        $otherClassStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherClassStudent->setRoles([UserRole::Student->value]);
        $otherClassStudent->setStatus(UserStatus::Active);
        $otherClassStudent->verify();
        $otherClassStudent->setFirstName('Other');
        $otherClassStudent->setLastName('Class');
        $otherClassStudent->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $otherClassEnrollment = new StudentEnrollment();
        $otherClassEnrollment->setStudent($otherClassStudent);
        $otherClassEnrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $otherClassEnrollment->setAcademicClass($otherClass);
        $otherClassEnrollment->setInstituteId($this->testInstituteId);
        $otherClassEnrollment->setAdmissionNumber('ADMOC' . $this->unique);
        $otherClassEnrollment->setIsCurrent(true);
        $otherClassEnrollment->setStatus(StudentEnrollmentStatus::Active);

        $entityManager->persist($otherClass);
        $entityManager->persist($otherClassStudent);
        $entityManager->persist($otherClassEnrollment);
        $entityManager->flush();

        $client->request('GET', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/student-participation', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(2, $data['data']['summary']['eligible']);

        $emails = array_column($data['data']['items'], 'email');
        $this->assertNotContains('other-class-student-participation-test-' . $this->unique . '@edupulse.ai', $emails);
    }

    public function testStudentWithNoSectionIsEligibleForClassLevelExam(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $noSectionStudent = new User();
        $noSectionStudent->setEmail('nosection-student-participation-test-' . $this->unique . '@edupulse.ai');
        $noSectionStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $noSectionStudent->setRoles([UserRole::Student->value]);
        $noSectionStudent->setStatus(UserStatus::Active);
        $noSectionStudent->verify();
        $noSectionStudent->setFirstName('No');
        $noSectionStudent->setLastName('Section');
        $noSectionStudent->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $noSectionEnrollment = new StudentEnrollment();
        $noSectionEnrollment->setStudent($noSectionStudent);
        $noSectionEnrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $noSectionEnrollment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $noSectionEnrollment->setInstituteId($this->testInstituteId);
        $noSectionEnrollment->setAdmissionNumber('ADMNS' . $this->unique);
        $noSectionEnrollment->setIsCurrent(true);
        $noSectionEnrollment->setStatus(StudentEnrollmentStatus::Active);

        $entityManager->persist($noSectionStudent);
        $entityManager->persist($noSectionEnrollment);
        $entityManager->flush();

        $client->request('GET', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/student-participation', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(3, $data['data']['summary']['eligible']);

        $emails = array_column($data['data']['items'], 'email');
        $this->assertContains('nosection-student-participation-test-' . $this->unique . '@edupulse.ai', $emails);
    }

    public function testStudentWithDifferentSectionIsExcludedWhenExamIsSectionRestricted(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/student-participation', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(2, $data['data']['summary']['eligible']);

        $emails = array_column($data['data']['items'], 'email');
        $this->assertNotContains('other-student-participation-test-' . $this->unique . '@edupulse.ai', $emails);
    }

    public function testSectionFilterWorks(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/student-participation', [
            'sectionId' => (string) $this->testSectionId,
        ], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(1, $data['data']['summary']['eligible']);

        $emails = array_column($data['data']['items'], 'email');
        $this->assertContains('student-participation-test-' . $this->unique . '@edupulse.ai', $emails);
        $this->assertNotContains('other-student-participation-test-' . $this->unique . '@edupulse.ai', $emails);
    }

    public function testSearchWorks(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/student-participation', [
            'search' => 'rahul',
        ], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(1, $data['data']['summary']['eligible']);

        $emails = array_column($data['data']['items'], 'email');
        $this->assertContains('student-participation-test-' . $this->unique . '@edupulse.ai', $emails);
    }

    public function testPaginationWorks(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/student-participation', [
            'page' => 1,
            'limit' => 1,
        ], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(1, $data['data']['pagination']['limit']);
        $this->assertSame(1, $data['data']['pagination']['page']);
        $this->assertSame(2, $data['data']['pagination']['totalItems']);
        $this->assertSame(2, $data['data']['pagination']['totalPages']);
        $this->assertCount(1, $data['data']['items']);
    }

    public function testStatusFilterNotAttempted(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/student-participation', [
            'status' => 'NOT_ATTEMPTED',
        ], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(2, $data['data']['summary']['eligible']);
        $this->assertSame(2, $data['data']['summary']['notAttempted']);
        $this->assertCount(2, $data['data']['items']);

        foreach ($data['data']['items'] as $item) {
            $this->assertSame('NOT_ATTEMPTED', $item['participationStatus']);
            $this->assertNull($item['attempt']);
        }
    }

    public function testSummaryCountsAreCorrectWithAttempts(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $attempt = new ExamAttempt();
        $attempt->setExamSession($entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId));
        $attempt->setStudent($entityManager->getRepository(User::class)->findOneBy(['email' => 'student-participation-test-' . $this->unique . '@edupulse.ai']));
        $attempt->setStatus(ExamAttemptStatus::Submitted);
        $attempt->setStartedAt(new \DateTimeImmutable('2025-01-01 09:00:00'));
        $attempt->setSubmittedAt(new \DateTimeImmutable('2025-01-01 10:00:00'));
        $attempt->setTimeTakenSeconds(3600);
        $attempt->setScore(10);
        $attempt->setMaxMarks(10);
        $attempt->setPercentage(100);

        $entityManager->persist($attempt);
        $entityManager->flush();

        $client->request('GET', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/student-participation', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(2, $data['data']['summary']['eligible']);
        $this->assertSame(1, $data['data']['summary']['notAttempted']);
        $this->assertSame(1, $data['data']['summary']['submitted']);

        $items = $data['data']['items'];
        $rahul = array_values(array_filter($items, fn ($item) => $item['email'] === 'student-participation-test-' . $this->unique . '@edupulse.ai'))[0];
        $this->assertSame('SUBMITTED', $rahul['participationStatus']);
        $this->assertNotNull($rahul['attempt']);
        $this->assertSame(10, $rahul['attempt']['score']);
        $this->assertSame(100, $rahul['attempt']['percentage']);
    }

    public function testInProgressAttemptStatus(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $attempt = new ExamAttempt();
        $attempt->setExamSession($entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId));
        $attempt->setStudent($entityManager->getRepository(User::class)->findOneBy(['email' => 'student-participation-test-' . $this->unique . '@edupulse.ai']));
        $attempt->setStatus(ExamAttemptStatus::InProgress);
        $attempt->setStartedAt(new \DateTimeImmutable('2025-01-01 09:00:00'));

        $entityManager->persist($attempt);
        $entityManager->flush();

        $client->request('GET', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/student-participation', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(1, $data['data']['summary']['inProgress']);

        $items = $data['data']['items'];
        $rahul = array_values(array_filter($items, fn ($item) => $item['email'] === 'student-participation-test-' . $this->unique . '@edupulse.ai'))[0];
        $this->assertSame('IN_PROGRESS', $rahul['participationStatus']);
    }

    public function testAutoSubmittedAttemptStatus(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $attempt = new ExamAttempt();
        $attempt->setExamSession($entityManager->getRepository(ExamSession::class)->find($this->testExamSessionId));
        $attempt->setStudent($entityManager->getRepository(User::class)->findOneBy(['email' => 'student-participation-test-' . $this->unique . '@edupulse.ai']));
        $attempt->setStatus(ExamAttemptStatus::AutoSubmitted);
        $attempt->setStartedAt(new \DateTimeImmutable('2025-01-01 09:00:00'));
        $attempt->setAutoSubmittedAt(new \DateTimeImmutable('2025-01-01 10:00:00'));

        $entityManager->persist($attempt);
        $entityManager->flush();

        $client->request('GET', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/student-participation', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(1, $data['data']['summary']['autoSubmitted']);

        $items = $data['data']['items'];
        $rahul = array_values(array_filter($items, fn ($item) => $item['email'] === 'student-participation-test-' . $this->unique . '@edupulse.ai'))[0];
        $this->assertSame('AUTO_SUBMITTED', $rahul['participationStatus']);
    }

    public function testArchivedEnrollmentIsExcluded(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $archivedStudent = new User();
        $archivedStudent->setEmail('archived-student-participation-test-' . $this->unique . '@edupulse.ai');
        $archivedStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $archivedStudent->setRoles([UserRole::Student->value]);
        $archivedStudent->setStatus(UserStatus::Active);
        $archivedStudent->verify();
        $archivedStudent->setFirstName('Archived');
        $archivedStudent->setLastName('Student');
        $archivedStudent->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $archivedEnrollment = new StudentEnrollment();
        $archivedEnrollment->setStudent($archivedStudent);
        $archivedEnrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $archivedEnrollment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $archivedEnrollment->setInstituteId($this->testInstituteId);
        $archivedEnrollment->setAdmissionNumber('ADMAR' . $this->unique);
        $archivedEnrollment->setIsCurrent(false);
        $archivedEnrollment->setStatus(StudentEnrollmentStatus::Archived);

        $entityManager->persist($archivedStudent);
        $entityManager->persist($archivedEnrollment);
        $entityManager->flush();

        $client->request('GET', '/api/v1/exam-sessions/' . $this->testExamSessionId . '/student-participation', [], [], $this->getAuthHeaders($this->superAdminToken));

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(2, $data['data']['summary']['eligible']);

        $emails = array_column($data['data']['items'], 'email');
        $this->assertNotContains('archived-student-participation-test-' . $this->unique . '@edupulse.ai', $emails);
    }
}
