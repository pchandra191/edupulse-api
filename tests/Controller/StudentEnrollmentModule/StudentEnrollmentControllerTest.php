<?php
declare(strict_types=1);

namespace App\Tests\Controller\StudentEnrollmentModule;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\EnrollmentSection;
use App\Entity\Institute;
use App\Entity\Section;
use App\Entity\StudentEnrollment;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\EnrollmentSectionStatus;
use App\Enum\InstituteStatus;
use App\Enum\SectionStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class StudentEnrollmentControllerTest extends WebTestCase
{
    private ?string $superAdminToken = null;
    private ?string $instituteToken = null;
    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
    private ?Uuid $testClassId = null;
    private ?Uuid $testStudentId = null;
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private array $createdEmails = [];
    private ?Uuid $otherInstituteId = null;
    private ?Uuid $otherAcademicYearId = null;
    private ?Uuid $otherClassId = null;
    private ?Uuid $otherStudentId = null;
    private ?Uuid $otherEnrollmentId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $unique = uniqid();
        $this->testInstituteId = Uuid::v7();
        $this->testAcademicYearId = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-se-test-' . $unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for SE');
        $institute->setCode('TESTSE' . $unique);
        $institute->setEmail('test-institute-se-' . $unique . '@edupulse.ai');
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

        $student = new User();
        $this->testStudentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($student);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($student, $this->testStudentId);
        $student->setEmail('student-se-test-' . $unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setFirstName('Test Student');
        $student->setLastName('SE Test');
        $student->setInstitute($institute);

        $instituteUser = new User();
        $instituteUser->setEmail('institute-se-test-' . $unique . '@edupulse.ai');
        $instituteUser->setPassword(password_hash('InstituteUser123!', PASSWORD_BCRYPT));
        $instituteUser->setRoles([UserRole::Institute->value]);
        $instituteUser->setStatus(UserStatus::Active);
        $instituteUser->verify();
        $instituteUser->setInstitute($institute);

        $entityManager->persist($superAdmin);
        $entityManager->persist($institute);
        $entityManager->persist($academicYear);
        $entityManager->persist($academicClass);
        $entityManager->persist($student);
        $entityManager->persist($instituteUser);
        $entityManager->flush();

        $this->testInstituteId = $institute->getId();
        $this->testAcademicYearId = $academicYear->getId();
        $this->testClassId = $academicClass->getId();
        $this->testStudentId = $student->getId();

        $this->createdEmails = [
            'superadmin-se-test-' . $unique . '@edupulse.ai',
            'institute-se-test-' . $unique . '@edupulse.ai',
            'student-se-test-' . $unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login($this->client, 'superadmin-se-test-' . $unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->instituteToken = $this->login($this->client, 'institute-se-test-' . $unique . '@edupulse.ai', 'InstituteUser123!');
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
        $this->testStudentId = null;
        $this->otherInstituteId = null;
        $this->otherAcademicYearId = null;
        $this->otherClassId = null;
        $this->otherStudentId = null;
        $this->otherEnrollmentId = null;
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

    private function createOtherInstituteEnrollment(): StudentEnrollment
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

        $otherInstitute = new Institute();
        $this->otherInstituteId = Uuid::v7();
        $instituteReflection = new \ReflectionClass($otherInstitute);
        $instituteProperty = $instituteReflection->getProperty('id');
        $instituteProperty->setAccessible(true);
        $instituteProperty->setValue($otherInstitute, $this->otherInstituteId);
        $otherInstitute->setName('Other Institute SE');
        $otherInstitute->setCode('OTHRSE' . uniqid());
        $otherInstitute->setEmail('other-se-' . uniqid() . '@edupulse.ai');
        $otherInstitute->setStatus(InstituteStatus::Active);

        $otherYear = new AcademicYear();
        $this->otherAcademicYearId = Uuid::v7();
        $otherYear->setInstitute($otherInstitute);
        $otherYear->setName('Other Year SE');
        $otherYear->setCode('OYSE' . uniqid());
        $otherYear->setStartDate(new \DateTimeImmutable('2024-01-01'));
        $otherYear->setEndDate(new \DateTimeImmutable('2024-12-31'));
        $otherYear->setStatus(AcademicYearStatus::Active);
        $otherYear->setIsActive(true);

        $otherClass = new AcademicClass();
        $this->otherClassId = Uuid::v7();
        $classReflection = new \ReflectionClass($otherClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($otherClass, $this->otherClassId);
        $otherClass->setInstitute($otherInstitute);
        $otherClass->setAcademicYear($otherYear);
        $otherClass->setName('Other Class SE');
        $otherClass->setCode('OCLS' . uniqid());
        $otherClass->setStatus(ClassStatus::Active);

        $otherStudent = new User();
        $this->otherStudentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($otherStudent);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($otherStudent, $this->otherStudentId);
        $otherStudent->setEmail('other-student-se-' . uniqid() . '@edupulse.ai');
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();
        $otherStudent->setFirstName('Other Student');
        $otherStudent->setLastName('SE');
        $otherStudent->setInstitute($otherInstitute);

        $otherEnrollment = new StudentEnrollment();
        $this->otherEnrollmentId = Uuid::v7();
        $enrollmentReflection = new \ReflectionClass($otherEnrollment);
        $enrollmentProperty = $enrollmentReflection->getProperty('id');
        $enrollmentProperty->setAccessible(true);
        $enrollmentProperty->setValue($otherEnrollment, $this->otherEnrollmentId);
        $otherEnrollment->setStudent($otherStudent);
        $otherEnrollment->setAcademicYear($otherYear);
        $otherEnrollment->setAcademicClass($otherClass);
        $otherEnrollment->setInstituteId($this->otherInstituteId);
        $otherEnrollment->setAdmissionNumber('ADM-OTHER-' . uniqid());
        $otherEnrollment->setAdmissionDate(new \DateTimeImmutable('2024-01-01'));
        $otherEnrollment->setIsCurrent(true);
        $otherEnrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);

        $entityManager->persist($otherInstitute);
        $entityManager->persist($otherYear);
        $entityManager->persist($otherClass);
        $entityManager->persist($otherStudent);
        $entityManager->persist($otherEnrollment);
        $entityManager->flush();

        return $otherEnrollment;
    }

    public function testSuperAdminCanListStudentEnrollmentsAndSeeInstitute(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $enrollment = new StudentEnrollment();
        $enrollment->setStudent($entityManager->getRepository(User::class)->find($this->testStudentId));
        $enrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment->setInstituteId($this->testInstituteId);
        $enrollment->setAdmissionNumber('ADM-LIST-' . uniqid());
        $enrollment->setAdmissionDate(new \DateTimeImmutable('2024-01-01'));
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $entityManager->persist($enrollment);
        $entityManager->flush();

        $client->request('GET', '/api/v1/student-enrollments', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertIsArray($data['data']['items']);

        $found = false;
        foreach ($data['data']['items'] as $item) {
            if ($item['id'] === (string) $enrollment->getId()) {
                $found = true;
                $this->assertArrayHasKey('institute', $item);
                $this->assertSame((string) $this->testInstituteId, $item['institute']['id']);
                $this->assertSame('Test Institute for SE', $item['institute']['name']);
                $this->assertArrayHasKey('academicClass', $item);
                $this->assertSame((string) $this->testClassId, $item['academicClass']['id']);
                break;
            }
        }
        $this->assertTrue($found, 'Created enrollment not found in list response');
    }

    public function testSuperAdminCanGetStudentEnrollmentAndSeeInstitute(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $enrollment = new StudentEnrollment();
        $enrollment->setStudent($entityManager->getRepository(User::class)->find($this->testStudentId));
        $enrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment->setInstituteId($this->testInstituteId);
        $enrollment->setAdmissionNumber('ADM-GET-' . uniqid());
        $enrollment->setAdmissionDate(new \DateTimeImmutable('2024-01-01'));
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $entityManager->persist($enrollment);
        $entityManager->flush();

        $client->request('GET', '/api/v1/student-enrollments/' . $enrollment->getId(), [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('institute', $data['data']);
        $this->assertSame((string) $this->testInstituteId, $data['data']['institute']['id']);
        $this->assertSame('Test Institute for SE', $data['data']['institute']['name']);
    }

    public function testSuperAdminCanCreateStudentEnrollmentAndSeeInstitute(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'studentId' => (string) $this->testStudentId,
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'admissionNumber' => 'ADM-CREATE-' . uniqid(),
            'admissionDate' => '2024-01-01',
            'rollNumber' => 'R001',
            'isCurrent' => true,
            'status' => 'ACTIVE',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/student-enrollments', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('institute', $data['data']);
        $this->assertSame((string) $this->testInstituteId, $data['data']['institute']['id']);
        $this->assertSame('Test Institute for SE', $data['data']['institute']['name']);
    }

    public function testSuperAdminCanUpdateStudentEnrollmentAndSeeInstitute(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $enrollment = new StudentEnrollment();
        $enrollment->setStudent($entityManager->getRepository(User::class)->find($this->testStudentId));
        $enrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment->setInstituteId($this->testInstituteId);
        $enrollment->setAdmissionNumber('ADM-UPDATE-' . uniqid());
        $enrollment->setAdmissionDate(new \DateTimeImmutable('2024-01-01'));
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $entityManager->persist($enrollment);
        $entityManager->flush();

        $payload = json_encode([
            'remarks' => 'Updated remarks',
        ]);

        $client->request('PUT', '/api/v1/student-enrollments/' . $enrollment->getId(), [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('institute', $data['data']);
        $this->assertSame((string) $this->testInstituteId, $data['data']['institute']['id']);
        $this->assertSame('Test Institute for SE', $data['data']['institute']['name']);
    }

    public function testSuperAdminCanDeleteStudentEnrollment(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $enrollment = new StudentEnrollment();
        $enrollment->setStudent($entityManager->getRepository(User::class)->find($this->testStudentId));
        $enrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment->setInstituteId($this->testInstituteId);
        $enrollment->setAdmissionNumber('ADM-DEL-' . uniqid());
        $enrollment->setAdmissionDate(new \DateTimeImmutable('2024-01-01'));
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $entityManager->persist($enrollment);
        $entityManager->flush();

        $client->request('DELETE', '/api/v1/student-enrollments/' . $enrollment->getId(), [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('archived', strtolower($data['message']));

        $refreshedEnrollment = $entityManager->getRepository(StudentEnrollment::class)->find($enrollment->getId());
        $this->assertNotNull($refreshedEnrollment);
        $this->assertSame(\App\Enum\StudentEnrollmentStatus::Archived->value, $refreshedEnrollment->getStatus()->value);
    }

    public function testInstituteUserCanListOwnEnrollmentsAndSeeInstitute(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $enrollment = new StudentEnrollment();
        $enrollment->setStudent($entityManager->getRepository(User::class)->find($this->testStudentId));
        $enrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment->setInstituteId($this->testInstituteId);
        $enrollment->setAdmissionNumber('ADM-INST-' . uniqid());
        $enrollment->setAdmissionDate(new \DateTimeImmutable('2024-01-01'));
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $entityManager->persist($enrollment);
        $entityManager->flush();

        $client->request('GET', '/api/v1/student-enrollments', [], [], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['data']['items']);

        $found = false;
        foreach ($data['data']['items'] as $item) {
            if ($item['id'] === (string) $enrollment->getId()) {
                $found = true;
                $this->assertArrayHasKey('institute', $item);
                $this->assertSame((string) $this->testInstituteId, $item['institute']['id']);
                break;
            }
        }
        $this->assertTrue($found, 'Own enrollment not found in institute list');
    }

    public function testInstituteUserCannotAccessOtherInstitutesEnrollment(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherEnrollment = $this->createOtherInstituteEnrollment();

        $client->request('GET', '/api/v1/student-enrollments/' . $otherEnrollment->getId(), [], [], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDuplicateEnrollmentForSameStudentYearClassIsRejected(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $enrollment = new StudentEnrollment();
        $enrollment->setStudent($entityManager->getRepository(User::class)->find($this->testStudentId));
        $enrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment->setInstituteId($this->testInstituteId);
        $enrollment->setAdmissionNumber('ADM-DUP1-' . uniqid());
        $enrollment->setAdmissionDate(new \DateTimeImmutable('2024-01-01'));
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $entityManager->persist($enrollment);
        $entityManager->flush();

        $payload = json_encode([
            'studentId' => (string) $this->testStudentId,
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'admissionNumber' => 'ADM-DUP2-' . uniqid(),
            'admissionDate' => '2024-01-01',
            'rollNumber' => 'R002',
            'isCurrent' => false,
            'status' => 'ACTIVE',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/student-enrollments', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('already has an enrollment', strtolower($data['message']));
    }

    public function testDuplicateRollNumberInSameYearClassIsRejected(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $studentA = new User();
        $studentAId = Uuid::v7();
        $studentAReflection = new \ReflectionClass($studentA);
        $studentAProperty = $studentAReflection->getProperty('id');
        $studentAProperty->setAccessible(true);
        $studentAProperty->setValue($studentA, $studentAId);
        $studentA->setEmail('student-dup-roll-a-' . uniqid() . '@edupulse.ai');
        $studentA->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $studentA->setRoles([UserRole::Student->value]);
        $studentA->setStatus(UserStatus::Active);
        $studentA->verify();
        $studentA->setFirstName('Student A');
        $studentA->setLastName('Roll Dup');
        $studentA->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $studentB = new User();
        $studentBId = Uuid::v7();
        $studentBReflection = new \ReflectionClass($studentB);
        $studentBProperty = $studentBReflection->getProperty('id');
        $studentBProperty->setAccessible(true);
        $studentBProperty->setValue($studentB, $studentBId);
        $studentB->setEmail('student-dup-roll-b-' . uniqid() . '@edupulse.ai');
        $studentB->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $studentB->setRoles([UserRole::Student->value]);
        $studentB->setStatus(UserStatus::Active);
        $studentB->verify();
        $studentB->setFirstName('Student B');
        $studentB->setLastName('Roll Dup');
        $studentB->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $enrollment1 = new StudentEnrollment();
        $enrollment1->setStudent($studentA);
        $enrollment1->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment1->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment1->setInstituteId($this->testInstituteId);
        $enrollment1->setRollNumber('DUP-R-01');
        $enrollment1->setAdmissionNumber('ADM-DUP-R-' . uniqid());
        $enrollment1->setAdmissionDate(new \DateTimeImmutable('2024-01-01'));
        $enrollment1->setIsCurrent(true);
        $enrollment1->setStatus(\App\Enum\StudentEnrollmentStatus::Active);

        $entityManager->persist($studentA);
        $entityManager->persist($studentB);
        $entityManager->persist($enrollment1);
        $entityManager->flush();

        $payload = json_encode([
            'studentId' => (string) $studentBId,
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'admissionNumber' => 'ADM-DUP-R2-' . uniqid(),
            'admissionDate' => '2024-01-01',
            'rollNumber' => 'DUP-R-01',
            'isCurrent' => false,
            'status' => 'ACTIVE',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/student-enrollments', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('roll number', strtolower($data['message']));
    }

    public function testSameRollNumberAllowedInDifferentClass(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherStudent = new User();
        $otherStudentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($otherStudent);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($otherStudent, $otherStudentId);
        $otherStudent->setEmail('other-student-same-roll-' . uniqid() . '@edupulse.ai');
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();
        $otherStudent->setFirstName('Other Student');
        $otherStudent->setLastName('Same Roll');
        $otherStudent->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $otherClass = new AcademicClass();
        $otherClassId = Uuid::v7();
        $classReflection = new \ReflectionClass($otherClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($otherClass, $otherClassId);
        $otherClass->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $otherClass->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $otherClass->setName('Other Class for Roll');
        $otherClass->setCode('OCLSR' . uniqid());
        $otherClass->setStatus(ClassStatus::Active);

        $anotherClass = new AcademicClass();
        $anotherClassId = Uuid::v7();
        $anotherClassReflection = new \ReflectionClass($anotherClass);
        $anotherClassProperty = $anotherClassReflection->getProperty('id');
        $anotherClassProperty->setAccessible(true);
        $anotherClassProperty->setValue($anotherClass, $anotherClassId);
        $anotherClass->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $anotherClass->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $anotherClass->setName('Another Class for Roll');
        $anotherClass->setCode('ACLSR' . uniqid());
        $anotherClass->setStatus(ClassStatus::Active);

        $enrollment1 = new StudentEnrollment();
        $enrollment1->setStudent($otherStudent);
        $enrollment1->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment1->setAcademicClass($otherClass);
        $enrollment1->setInstituteId($this->testInstituteId);
        $enrollment1->setRollNumber('SAME-R-01');
        $enrollment1->setAdmissionNumber('ADM-SAME-R-' . uniqid());
        $enrollment1->setAdmissionDate(new \DateTimeImmutable('2024-01-01'));
        $enrollment1->setIsCurrent(true);
        $enrollment1->setStatus(\App\Enum\StudentEnrollmentStatus::Active);

        $entityManager->persist($otherStudent);
        $entityManager->persist($otherClass);
        $entityManager->persist($anotherClass);
        $entityManager->persist($enrollment1);
        $entityManager->flush();

        $payload = json_encode([
            'studentId' => (string) $otherStudentId,
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $anotherClassId,
            'admissionNumber' => 'ADM-SAME-R2-' . uniqid(),
            'admissionDate' => '2024-01-01',
            'rollNumber' => 'SAME-R-01',
            'isCurrent' => false,
            'status' => 'ACTIVE',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/student-enrollments', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        file_put_contents('F:/Projects/React/edupulse-api/tmp_se_roll.txt', 'STATUS: ' . $response->getStatusCode() . PHP_EOL . 'CONTENT: ' . $response->getContent() . PHP_EOL);
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testSameAdmissionNumberDifferentClassSameYearIsRejected(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherClass = new AcademicClass();
        $otherClassId = Uuid::v7();
        $classReflection = new \ReflectionClass($otherClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($otherClass, $otherClassId);
        $otherClass->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $otherClass->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $otherClass->setName('Other Class for Admission');
        $otherClass->setCode('OCLSADM' . uniqid());
        $otherClass->setStatus(ClassStatus::Active);

        $otherStudent = new User();
        $otherStudentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($otherStudent);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($otherStudent, $otherStudentId);
        $otherStudent->setEmail('other-student-admission-' . uniqid() . '@edupulse.ai');
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();
        $otherStudent->setFirstName('Other Student');
        $otherStudent->setLastName('Admission');
        $otherStudent->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $entityManager->persist($otherClass);
        $entityManager->persist($otherStudent);
        $entityManager->flush();

        $payload1 = json_encode([
            'studentId' => (string) $this->testStudentId,
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'admissionNumber' => 'A11-2026',
            'admissionDate' => '2024-01-01',
            'rollNumber' => 'R001',
            'isCurrent' => true,
            'status' => 'ACTIVE',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/student-enrollments', [], [], $this->getAuthHeaders($this->superAdminToken), $payload1);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $payload2 = json_encode([
            'studentId' => (string) $otherStudentId,
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $otherClassId,
            'admissionNumber' => 'A11-2026',
            'admissionDate' => '2024-01-01',
            'rollNumber' => 'R002',
            'isCurrent' => false,
            'status' => 'ACTIVE',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/student-enrollments', [], [], $this->getAuthHeaders($this->superAdminToken), $payload2);
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('admission number', strtolower($data['message']));
    }

    public function testSameAdmissionNumberDifferentYearIsAllowed(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherYear = new AcademicYear();
        $otherYearId = Uuid::v7();
        $ayReflection = new \ReflectionClass($otherYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($otherYear, $otherYearId);
        $otherYear->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $otherYear->setName('Other Year for Admission');
        $otherYear->setCode('OYA' . uniqid());
        $otherYear->setStartDate(new \DateTimeImmutable('2025-01-01'));
        $otherYear->setEndDate(new \DateTimeImmutable('2025-12-31'));
        $otherYear->setStatus(AcademicYearStatus::Active);
        $otherYear->setIsActive(true);

        $otherStudent = new User();
        $otherStudentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($otherStudent);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($otherStudent, $otherStudentId);
        $otherStudent->setEmail('other-student-admission-year-' . uniqid() . '@edupulse.ai');
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();
        $otherStudent->setFirstName('Other Student');
        $otherStudent->setLastName('Admission Year');
        $otherStudent->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $entityManager->persist($otherYear);
        $entityManager->persist($otherStudent);
        $entityManager->flush();

        $payload1 = json_encode([
            'studentId' => (string) $this->testStudentId,
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'admissionNumber' => 'A11-2026',
            'admissionDate' => '2024-01-01',
            'rollNumber' => 'R001',
            'isCurrent' => true,
            'status' => 'ACTIVE',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/student-enrollments', [], [], $this->getAuthHeaders($this->superAdminToken), $payload1);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $payload2 = json_encode([
            'studentId' => (string) $otherStudentId,
            'academicYearId' => (string) $otherYearId,
            'academicClassId' => (string) $this->testClassId,
            'admissionNumber' => 'A11-2026',
            'admissionDate' => '2025-01-01',
            'rollNumber' => 'R002',
            'isCurrent' => false,
            'status' => 'ACTIVE',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/student-enrollments', [], [], $this->getAuthHeaders($this->superAdminToken), $payload2);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testSameAdmissionNumberDifferentInstituteIsAllowed(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherEnrollment = $this->createOtherInstituteEnrollment();
        $otherEnrollment->setAdmissionNumber('A11-2026');
        $entityManager->flush();

        $payload = json_encode([
            'studentId' => (string) $this->testStudentId,
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'admissionNumber' => 'A11-2026',
            'admissionDate' => '2024-01-01',
            'rollNumber' => 'R003',
            'isCurrent' => false,
            'status' => 'ACTIVE',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/student-enrollments', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testUpdateAdmissionNumberToExistingSameYearIsRejected(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherStudent = new User();
        $otherStudentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($otherStudent);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($otherStudent, $otherStudentId);
        $otherStudent->setEmail('other-student-update-adm-' . uniqid() . '@edupulse.ai');
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();
        $otherStudent->setFirstName('Other Student');
        $otherStudent->setLastName('Update Admission');
        $otherStudent->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $enrollment1 = new StudentEnrollment();
        $enrollment1->setStudent($entityManager->getRepository(User::class)->find($this->testStudentId));
        $enrollment1->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment1->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment1->setInstituteId($this->testInstituteId);
        $enrollment1->setAdmissionNumber('A11-UPDATE-1');
        $enrollment1->setAdmissionDate(new \DateTimeImmutable('2024-01-01'));
        $enrollment1->setIsCurrent(true);
        $enrollment1->setStatus(\App\Enum\StudentEnrollmentStatus::Active);

        $enrollment2 = new StudentEnrollment();
        $enrollment2->setStudent($otherStudent);
        $enrollment2->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment2->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment2->setInstituteId($this->testInstituteId);
        $enrollment2->setAdmissionNumber('A11-UPDATE-2');
        $enrollment2->setAdmissionDate(new \DateTimeImmutable('2024-01-01'));
        $enrollment2->setIsCurrent(false);
        $enrollment2->setStatus(\App\Enum\StudentEnrollmentStatus::Active);

        $entityManager->persist($otherStudent);
        $entityManager->persist($enrollment1);
        $entityManager->persist($enrollment2);
        $entityManager->flush();

        $payload = json_encode([
            'admissionNumber' => 'A11-UPDATE-2',
        ]);

        $client->request('PUT', '/api/v1/student-enrollments/' . $enrollment1->getId(), [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('admission number', strtolower($data['message']));
    }

    public function testUpdateAdmissionNumberToDifferentYearIsAllowed(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherYear = new AcademicYear();
        $otherYearId = Uuid::v7();
        $ayReflection = new \ReflectionClass($otherYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($otherYear, $otherYearId);
        $otherYear->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
        $otherYear->setName('Other Year for Update Admission');
        $otherYear->setCode('OYUA' . uniqid());
        $otherYear->setStartDate(new \DateTimeImmutable('2025-01-01'));
        $otherYear->setEndDate(new \DateTimeImmutable('2025-12-31'));
        $otherYear->setStatus(AcademicYearStatus::Active);
        $otherYear->setIsActive(true);

        $otherStudent = new User();
        $otherStudentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($otherStudent);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($otherStudent, $otherStudentId);
        $otherStudent->setEmail('other-student-update-year-' . uniqid() . '@edupulse.ai');
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();
        $otherStudent->setFirstName('Other Student');
        $otherStudent->setLastName('Update Year');
        $otherStudent->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $enrollment1 = new StudentEnrollment();
        $enrollment1->setStudent($entityManager->getRepository(User::class)->find($this->testStudentId));
        $enrollment1->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment1->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment1->setInstituteId($this->testInstituteId);
        $enrollment1->setAdmissionNumber('A11-UPDATE-Y1');
        $enrollment1->setAdmissionDate(new \DateTimeImmutable('2024-01-01'));
        $enrollment1->setIsCurrent(true);
        $enrollment1->setStatus(\App\Enum\StudentEnrollmentStatus::Active);

        $enrollment2 = new StudentEnrollment();
        $enrollment2->setStudent($otherStudent);
        $enrollment2->setAcademicYear($otherYear);
        $enrollment2->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment2->setInstituteId($this->testInstituteId);
        $enrollment2->setAdmissionNumber('A11-UPDATE-Y2');
        $enrollment2->setAdmissionDate(new \DateTimeImmutable('2025-01-01'));
        $enrollment2->setIsCurrent(false);
        $enrollment2->setStatus(\App\Enum\StudentEnrollmentStatus::Active);

        $entityManager->persist($otherYear);
        $entityManager->persist($otherStudent);
        $entityManager->persist($enrollment1);
        $entityManager->persist($enrollment2);
        $entityManager->flush();

        $payload = json_encode([
            'admissionNumber' => 'A11-UPDATE-Y2',
        ]);

        $client->request('PUT', '/api/v1/student-enrollments/' . $enrollment1->getId(), [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('A11-UPDATE-Y2', $data['data']['admissionNumber']);
    }

    public function testSuperAdminCanUpdateEnrollmentSectionStatus(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $section = new Section();
        $section->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $section->setCode('SEC' . substr(uniqid(), -6));
        $section->setName('Test Section for Status');
        $section->setStatus(\App\Enum\SectionStatus::Active);
        $entityManager->persist($section);
        $entityManager->flush();

        $enrollment = new StudentEnrollment();
        $enrollment->setStudent($entityManager->getRepository(User::class)->find($this->testStudentId));
        $enrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment->setInstituteId($this->testInstituteId);
        $enrollment->setAdmissionNumber('ADM-SEC-STATUS-' . uniqid());
        $enrollment->setAdmissionDate(new \DateTimeImmutable('2024-01-01'));
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $entityManager->persist($enrollment);
        $entityManager->flush();

        $enrollmentSection = new EnrollmentSection();
        $enrollmentSection->setStudentEnrollment($enrollment);
        $enrollmentSection->setSection($section);
        $enrollmentSection->setStatus(EnrollmentSectionStatus::Active);
        $entityManager->persist($enrollmentSection);
        $entityManager->flush();

        $payload = json_encode([
            'status' => 'LEFT',
        ]);

        $client->request('PUT', '/api/v1/student-enrollments/' . $enrollment->getId() . '/sections/' . $section->getId(), [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('status updated', strtolower($data['message']));

        $refreshedEnrollmentSection = $entityManager->getRepository(EnrollmentSection::class)->find($enrollmentSection->getId());
        $this->assertNotNull($refreshedEnrollmentSection);
        $this->assertSame(EnrollmentSectionStatus::Left->value, $refreshedEnrollmentSection->getStatus()->value);
    }

    public function testArchivedStudentEnrollmentCannotBeUpdated(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $enrollment = new StudentEnrollment();
        $enrollment->setStudent($entityManager->getRepository(User::class)->find($this->testStudentId));
        $enrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment->setInstituteId($this->testInstituteId);
        $enrollment->setAdmissionNumber('ADM-ARCHIVE-UPD-' . uniqid());
        $enrollment->setAdmissionDate(new \DateTimeImmutable('2024-01-01'));
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $entityManager->persist($enrollment);
        $entityManager->flush();

        $client->request('DELETE', '/api/v1/student-enrollments/' . $enrollment->getId(), [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $payload = json_encode([
            'admissionNumber' => 'ADM-ARCHIVE-UPD-2',
        ]);

        $client->request('PUT', '/api/v1/student-enrollments/' . $enrollment->getId(), [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('archived', strtolower($data['message']));
    }

    public function testArchivedStudentEnrollmentCannotBeDeleted(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $enrollment = new StudentEnrollment();
        $enrollment->setStudent($entityManager->getRepository(User::class)->find($this->testStudentId));
        $enrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment->setInstituteId($this->testInstituteId);
        $enrollment->setAdmissionNumber('ADM-ARCHIVE-DEL-' . uniqid());
        $enrollment->setAdmissionDate(new \DateTimeImmutable('2024-01-01'));
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $entityManager->persist($enrollment);
        $entityManager->flush();

        $client->request('DELETE', '/api/v1/student-enrollments/' . $enrollment->getId(), [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('DELETE', '/api/v1/student-enrollments/' . $enrollment->getId(), [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('archived', strtolower($data['message']));
    }

    public function testStudentUserRemainsEditableWhenEnrollmentIsArchived(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $enrollment = new StudentEnrollment();
        $enrollment->setStudent($entityManager->getRepository(User::class)->find($this->testStudentId));
        $enrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $enrollment->setInstituteId($this->testInstituteId);
        $enrollment->setAdmissionNumber('ADM-USER-TEST-' . uniqid());
        $enrollment->setAdmissionDate(new \DateTimeImmutable('2024-01-01'));
        $enrollment->setIsCurrent(true);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Active);
        $entityManager->persist($enrollment);
        $entityManager->flush();

        $client->request('DELETE', '/api/v1/student-enrollments/' . $enrollment->getId(), [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $student = $entityManager->getRepository(User::class)->find($this->testStudentId);
        $student->setFirstName('Updated First Name');
        $entityManager->flush();

        $refreshedStudent = $entityManager->getRepository(User::class)->find($this->testStudentId);
        $this->assertSame('Updated First Name', $refreshedStudent->getFirstName());
    }
}
