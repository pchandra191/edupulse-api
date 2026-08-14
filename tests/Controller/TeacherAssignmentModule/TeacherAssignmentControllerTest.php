<?php
declare(strict_types=1);

namespace App\Tests\Controller\TeacherAssignmentModule;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\Institute;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\TeacherAssignment;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\InstituteStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class TeacherAssignmentControllerTest extends WebTestCase
{
    private ?string $superAdminToken = null;
    private ?string $instituteToken = null;
    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
    private ?Uuid $testClassId = null;
    private ?Uuid $testTeacherId = null;
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private array $createdEmails = [];
    private ?Uuid $otherInstituteId = null;
    private ?Uuid $otherClassId = null;
    private ?Uuid $otherAcademicYearId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $unique = uniqid();
        $this->testInstituteId = Uuid::v7();
        $this->testAcademicYearId = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-ta-test-' . $unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for TA');
        $institute->setCode('TESTTA' . $unique);
        $institute->setEmail('test-institute-ta-' . $unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);

        $academicYear = new AcademicYear();
        $ayReflection = new \ReflectionClass($academicYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($academicYear, $this->testAcademicYearId);
        $academicYear->setInstitute($institute);
        $academicYear->setName('Test Academic Year ' . $unique);
        $academicYear->setCode('AY' . $unique);
        $academicYear->setStartDate(new \DateTimeImmutable('2024-01-01'));
        $academicYear->setEndDate(new \DateTimeImmutable('2024-12-31'));
        $academicYear->setStatus(AcademicYearStatus::Active);
        $academicYear->setIsActive(true);

        $academicClass = new AcademicClass();
        $this->testClassId = Uuid::v7();
        $classReflection = new \ReflectionClass($academicClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($academicClass, $this->testClassId);
        $academicClass->setInstitute($institute);
        $academicClass->setAcademicYear($academicYear);
        $academicClass->setName('Test Class ' . $unique);
        $academicClass->setCode('CLS' . $unique);
        $academicClass->setStatus(ClassStatus::Active);

        $teacher = new User();
        $this->testTeacherId = Uuid::v7();
        $teacherReflection = new \ReflectionClass($teacher);
        $teacherProperty = $teacherReflection->getProperty('id');
        $teacherProperty->setAccessible(true);
        $teacherProperty->setValue($teacher, $this->testTeacherId);
        $teacher->setEmail('teacher-ta-test-' . $unique . '@edupulse.ai');
        $teacher->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
        $teacher->setRoles([UserRole::Teacher->value]);
        $teacher->setStatus(UserStatus::Active);
        $teacher->verify();
        $teacher->setInstitute($institute);

        $instituteUser = new User();
        $instituteUser->setEmail('institute-ta-test-' . $unique . '@edupulse.ai');
        $instituteUser->setPassword(password_hash('InstituteUser123!', PASSWORD_BCRYPT));
        $instituteUser->setRoles([UserRole::Institute->value]);
        $instituteUser->setStatus(UserStatus::Active);
        $instituteUser->verify();
        $instituteUser->setInstitute($institute);

        $entityManager->persist($superAdmin);
        $entityManager->persist($institute);
        $entityManager->persist($academicYear);
        $entityManager->persist($academicClass);
        $entityManager->persist($teacher);
        $entityManager->persist($instituteUser);
        $entityManager->flush();

        $this->testInstituteId = $institute->getId();
        $this->testAcademicYearId = $academicYear->getId();
        $this->testClassId = $academicClass->getId();
        $this->testTeacherId = $teacher->getId();

        $this->createdEmails = [
            'superadmin-ta-test-' . $unique . '@edupulse.ai',
            'institute-ta-test-' . $unique . '@edupulse.ai',
            'teacher-ta-test-' . $unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login($this->client, 'superadmin-ta-test-' . $unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->instituteToken = $this->login($this->client, 'institute-ta-test-' . $unique . '@edupulse.ai', 'InstituteUser123!');
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

        $this->superAdminToken = null;
        $this->instituteToken = null;
        $this->testInstituteId = null;
        $this->testAcademicYearId = null;
        $this->testClassId = null;
        $this->testTeacherId = null;
        $this->otherInstituteId = null;
        $this->otherClassId = null;
        $this->otherAcademicYearId = null;
        $this->createdEmails = [];

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

    private function createOtherInstituteData(): array
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

        $otherInstitute = new Institute();
        $this->otherInstituteId = Uuid::v7();
        $instituteReflection = new \ReflectionClass($otherInstitute);
        $instituteProperty = $instituteReflection->getProperty('id');
        $instituteProperty->setAccessible(true);
        $instituteProperty->setValue($otherInstitute, $this->otherInstituteId);
        $otherInstitute->setName('Other Institute TA');
        $otherInstitute->setCode('OTHRTA' . uniqid());
        $otherInstitute->setEmail('other-ta-' . uniqid() . '@edupulse.ai');
        $otherInstitute->setStatus(InstituteStatus::Active);

        $otherYear = new AcademicYear();
        $this->otherAcademicYearId = Uuid::v7();
        $otherYear->setInstitute($otherInstitute);
        $otherYear->setName('Other Year TA');
        $otherYear->setCode('OYTA' . uniqid());
        $otherYear->setStartDate(new \DateTimeImmutable('2024-01-01'));
        $otherYear->setEndDate(new \DateTimeImmutable('2024-12-31'));
        $otherYear->setStatus(AcademicYearStatus::Active);
        $otherYear->setIsActive(true);

        $otherTeacher = new User();
        $otherTeacherId = Uuid::v7();
        $teacherReflection = new \ReflectionClass($otherTeacher);
        $teacherProperty = $teacherReflection->getProperty('id');
        $teacherProperty->setAccessible(true);
        $teacherProperty->setValue($otherTeacher, $otherTeacherId);
        $otherTeacher->setEmail('other-teacher-ta-' . uniqid() . '@edupulse.ai');
        $otherTeacher->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
        $otherTeacher->setRoles([UserRole::Teacher->value]);
        $otherTeacher->setStatus(UserStatus::Active);
        $otherTeacher->verify();
        $otherTeacher->setInstitute($otherInstitute);

        $otherClass = new AcademicClass();
        $this->otherClassId = Uuid::v7();
        $classReflection = new \ReflectionClass($otherClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($otherClass, $this->otherClassId);
        $otherClass->setInstitute($otherInstitute);
        $otherClass->setAcademicYear($otherYear);
        $otherClass->setName('Other Class TA');
        $otherClass->setCode('OCLS' . uniqid());
        $otherClass->setStatus(ClassStatus::Active);

        $entityManager->persist($otherInstitute);
        $entityManager->persist($otherYear);
        $entityManager->persist($otherTeacher);
        $entityManager->persist($otherClass);
        $entityManager->flush();

        return [
            'institute' => $otherInstitute,
            'academicYear' => $otherYear,
            'teacher' => $otherTeacher,
            'academicClass' => $otherClass,
        ];
    }

    public function testSuperAdminCanListTeacherAssignments(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $assignment = new TeacherAssignment();
        $assignment->setTeacher($entityManager->getRepository(User::class)->find($this->testTeacherId));
        $assignment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $assignment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $assignment->setAssignmentType('SUBJECT_TEACHER');
        $assignment->setStatus(\App\Enum\TeacherAssignmentStatus::Active);
        $entityManager->persist($assignment);
        $entityManager->flush();

        $client->request('GET', '/api/v1/teacher-assignments', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertArrayHasKey('pagination', $data['data']);
        $this->assertIsArray($data['data']['items']);

        $found = false;
        foreach ($data['data']['items'] as $item) {
            if ($item['id'] === (string) $assignment->getId()) {
                $found = true;
                $this->assertArrayHasKey('institute', $item);
                $this->assertSame((string) $this->testInstituteId, $item['institute']['id']);
                $this->assertSame('Test Institute for TA', $item['institute']['name']);
                break;
            }
        }
        $this->assertTrue($found, 'Created assignment not found in list response');
    }

    public function testSuperAdminCanGetTeacherAssignmentById(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $assignment = new TeacherAssignment();
        $assignment->setTeacher($entityManager->getRepository(User::class)->find($this->testTeacherId));
        $assignment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $assignment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $assignment->setAssignmentType('CLASS_TEACHER');
        $assignment->setStatus(\App\Enum\TeacherAssignmentStatus::Active);
        $entityManager->persist($assignment);
        $entityManager->flush();

        $client->request('GET', '/api/v1/teacher-assignments/' . $assignment->getId(), [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame((string) $assignment->getId(), $data['data']['id']);
        $this->assertArrayHasKey('institute', $data['data']);
        $this->assertSame((string) $this->testInstituteId, $data['data']['institute']['id']);
        $this->assertSame('Test Institute for TA', $data['data']['institute']['name']);
        $this->assertSame('CLASS_TEACHER', $data['data']['assignmentType']);
    }

    public function testSuperAdminCanCreateTeacherAssignment(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'teacherId' => (string) $this->testTeacherId,
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'assignmentType' => 'SUBJECT_TEACHER',
            'status' => 'ACTIVE',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/teacher-assignments', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('institute', $data['data']);
        $this->assertSame((string) $this->testInstituteId, $data['data']['institute']['id']);
        $this->assertSame('Test Institute for TA', $data['data']['institute']['name']);
        $this->assertSame((string) $this->testTeacherId, $data['data']['teacher']['id']);
        $this->assertSame((string) $this->testAcademicYearId, $data['data']['academicYear']['id']);
        $this->assertSame((string) $this->testClassId, $data['data']['academicClass']['id']);
    }

    public function testSuperAdminCanUpdateTeacherAssignment(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $assignment = new TeacherAssignment();
        $assignment->setTeacher($entityManager->getRepository(User::class)->find($this->testTeacherId));
        $assignment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $assignment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $assignment->setAssignmentType('SUBJECT_TEACHER');
        $assignment->setRemarks('Original remarks');
        $assignment->setStatus(\App\Enum\TeacherAssignmentStatus::Active);
        $entityManager->persist($assignment);
        $entityManager->flush();

        $payload = json_encode([
            'remarks' => 'Updated remarks',
            'status' => 'INACTIVE',
        ]);

        $client->request('PUT', '/api/v1/teacher-assignments/' . $assignment->getId(), [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Updated remarks', $data['data']['remarks']);
        $this->assertSame('INACTIVE', $data['data']['status']);
        $this->assertArrayHasKey('institute', $data['data']);
        $this->assertSame((string) $this->testInstituteId, $data['data']['institute']['id']);
        $this->assertSame('Test Institute for TA', $data['data']['institute']['name']);
    }

    public function testSuperAdminCanDeleteTeacherAssignment(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $assignment = new TeacherAssignment();
        $assignment->setTeacher($entityManager->getRepository(User::class)->find($this->testTeacherId));
        $assignment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $assignment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $assignment->setAssignmentType('SUBJECT_TEACHER');
        $assignment->setStatus(\App\Enum\TeacherAssignmentStatus::Active);
        $entityManager->persist($assignment);
        $entityManager->flush();

        $client->request('DELETE', '/api/v1/teacher-assignments/' . $assignment->getId(), [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('archived', strtolower($data['message']));

        $refreshedAssignment = $entityManager->getRepository(TeacherAssignment::class)->find($assignment->getId());
        $this->assertNotNull($refreshedAssignment);
        $this->assertSame(\App\Enum\TeacherAssignmentStatus::Archived->value, $refreshedAssignment->getStatus()->value);
    }

    public function testInstituteUserCanListOwnTeacherAssignments(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $assignment = new TeacherAssignment();
        $assignment->setTeacher($entityManager->getRepository(User::class)->find($this->testTeacherId));
        $assignment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $assignment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $assignment->setAssignmentType('SUBJECT_TEACHER');
        $assignment->setStatus(\App\Enum\TeacherAssignmentStatus::Active);
        $entityManager->persist($assignment);
        $entityManager->flush();

        $client->request('GET', '/api/v1/teacher-assignments', [], [], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['data']['items']);

        $found = false;
        foreach ($data['data']['items'] as $item) {
            if ($item['id'] === (string) $assignment->getId()) {
                $found = true;
                $this->assertArrayHasKey('institute', $item);
                $this->assertSame((string) $this->testInstituteId, $item['institute']['id']);
                break;
            }
        }
        $this->assertTrue($found, 'Own assignment not found in institute list');
    }

    public function testInstituteUserCannotAccessAnotherInstitutesTeacherAssignment(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherData = $this->createOtherInstituteData();

        $otherAssignment = new TeacherAssignment();
        $otherAssignment->setTeacher($otherData['teacher']);
        $otherAssignment->setAcademicYear($otherData['academicYear']);
        $otherAssignment->setAcademicClass($otherData['academicClass']);
        $otherAssignment->setAssignmentType('SUBJECT_TEACHER');
        $otherAssignment->setStatus(\App\Enum\TeacherAssignmentStatus::Active);
        $entityManager->persist($otherAssignment);
        $entityManager->flush();

        $client->request('GET', '/api/v1/teacher-assignments/' . $otherAssignment->getId(), [], [], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testInstituteUserCannotCreateAssignmentWithClassFromAnotherInstitute(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherData = $this->createOtherInstituteData();

        $payload = json_encode([
            'teacherId' => (string) $this->testTeacherId,
            'academicYearId' => (string) $otherData['academicYear']->getId(),
            'academicClassId' => (string) $otherData['academicClass']->getId(),
            'assignmentType' => 'SUBJECT_TEACHER',
            'status' => 'ACTIVE',
        ]);

        $client->request('POST', '/api/v1/teacher-assignments', [], [], $this->getAuthHeaders($this->instituteToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('does not belong', $data['message']);
    }

    public function testInstituteUserCannotUpdateAnotherInstitutesTeacherAssignment(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherData = $this->createOtherInstituteData();

        $otherAssignment = new TeacherAssignment();
        $otherAssignment->setTeacher($otherData['teacher']);
        $otherAssignment->setAcademicYear($otherData['academicYear']);
        $otherAssignment->setAcademicClass($otherData['academicClass']);
        $otherAssignment->setAssignmentType('SUBJECT_TEACHER');
        $otherAssignment->setStatus(\App\Enum\TeacherAssignmentStatus::Active);
        $entityManager->persist($otherAssignment);
        $entityManager->flush();

        $payload = json_encode([
            'remarks' => 'Hacked remarks',
        ]);

        $client->request('PUT', '/api/v1/teacher-assignments/' . $otherAssignment->getId(), [], [], $this->getAuthHeaders($this->instituteToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testInstituteUserCannotDeleteAnotherInstitutesTeacherAssignment(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherData = $this->createOtherInstituteData();

        $otherAssignment = new TeacherAssignment();
        $otherAssignment->setTeacher($otherData['teacher']);
        $otherAssignment->setAcademicYear($otherData['academicYear']);
        $otherAssignment->setAcademicClass($otherData['academicClass']);
        $otherAssignment->setAssignmentType('SUBJECT_TEACHER');
        $otherAssignment->setStatus(\App\Enum\TeacherAssignmentStatus::Active);
        $entityManager->persist($otherAssignment);
        $entityManager->flush();

        $client->request('DELETE', '/api/v1/teacher-assignments/' . $otherAssignment->getId(), [], [], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testInvalidTeacherIdReturnsNotFound(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'teacherId' => (string) Uuid::v7(),
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'assignmentType' => 'SUBJECT_TEACHER',
            'status' => 'ACTIVE',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/teacher-assignments', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testInvalidAcademicYearIdReturnsNotFound(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'teacherId' => (string) $this->testTeacherId,
            'academicYearId' => (string) Uuid::v7(),
            'academicClassId' => (string) $this->testClassId,
            'assignmentType' => 'SUBJECT_TEACHER',
            'status' => 'ACTIVE',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/teacher-assignments', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testInvalidAcademicClassIdReturnsNotFound(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'teacherId' => (string) $this->testTeacherId,
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) Uuid::v7(),
            'assignmentType' => 'SUBJECT_TEACHER',
            'status' => 'ACTIVE',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/teacher-assignments', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testDuplicateAssignmentReturnsError(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $assignment1 = new TeacherAssignment();
        $assignment1->setTeacher($entityManager->getRepository(User::class)->find($this->testTeacherId));
        $assignment1->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $assignment1->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $assignment1->setAssignmentType('SUBJECT_TEACHER');
        $assignment1->setStatus(\App\Enum\TeacherAssignmentStatus::Active);
        $entityManager->persist($assignment1);
        $entityManager->flush();

        $payload = json_encode([
            'teacherId' => (string) $this->testTeacherId,
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'assignmentType' => 'SUBJECT_TEACHER',
            'status' => 'ACTIVE',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/teacher-assignments', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('already exists', strtolower($data['message']));
    }

    public function testSuperAdminCanCreateTeacherAssignmentWithTransferredStatus(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'teacherId' => (string) $this->testTeacherId,
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'assignmentType' => 'SUBJECT_TEACHER',
            'status' => 'TRANSFERRED',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/teacher-assignments', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('TRANSFERRED', $data['data']['status']);
    }

    public function testSuperAdminCanUpdateTeacherAssignmentToTransferredStatus(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $assignment = new TeacherAssignment();
        $assignment->setTeacher($entityManager->getRepository(User::class)->find($this->testTeacherId));
        $assignment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $assignment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $assignment->setAssignmentType('SUBJECT_TEACHER');
        $assignment->setStatus(\App\Enum\TeacherAssignmentStatus::Active);
        $entityManager->persist($assignment);
        $entityManager->flush();

        $payload = json_encode([
            'status' => 'TRANSFERRED',
        ]);

        $client->request('PUT', '/api/v1/teacher-assignments/' . $assignment->getId(), [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('TRANSFERRED', $data['data']['status']);
    }

    public function testInvalidTeacherAssignmentStatusReturnsValidationError(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $assignment = new TeacherAssignment();
        $assignment->setTeacher($entityManager->getRepository(User::class)->find($this->testTeacherId));
        $assignment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $assignment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $assignment->setAssignmentType('SUBJECT_TEACHER');
        $assignment->setStatus(\App\Enum\TeacherAssignmentStatus::Active);
        $entityManager->persist($assignment);
        $entityManager->flush();

        $payload = json_encode([
            'status' => 'INVALID_STATUS',
        ]);

        $client->request('PUT', '/api/v1/teacher-assignments/' . $assignment->getId(), [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('status', $data['errors']);
    }
}
