<?php
declare(strict_types=1);

namespace App\Tests\Controller\ExaminationModule;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\Institute;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\ExaminationStatus;
use App\Enum\InstituteStatus;
use App\Enum\SectionStatus;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class ExaminationControllerTest extends WebTestCase
{
    private ?string $superAdminToken = null;
    private ?string $instituteToken = null;
    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
    private ?Uuid $testClassId = null;
    private ?Uuid $testSubjectId = null;
    private ?Uuid $testSectionId = null;
    private ?Uuid $testExaminationId = null;
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private array $createdEmails = [];
    private ?Uuid $existingInstituteId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $unique = uniqid();
        $this->testInstituteId = Uuid::v7();
        $this->testAcademicYearId = Uuid::v7();
        $this->testClassId = Uuid::v7();
        $this->testSubjectId = Uuid::v7();
        $this->testSectionId = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-test-' . $unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute');
        $institute->setCode('TEST' . $unique);
        $institute->setEmail('test-institute-' . $unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);

        $academicYear = new AcademicYear();
        $ayReflection = new \ReflectionClass($academicYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($academicYear, $this->testAcademicYearId);
        $academicYear->setInstitute($institute);
        $academicYear->setName('Test Academic Year');
        $academicYear->setCode('TAY' . $unique);
        $academicYear->setStartDate(new \DateTimeImmutable('2025-01-01'));
        $academicYear->setEndDate(new \DateTimeImmutable('2025-12-31'));
        $academicYear->setStatus(AcademicYearStatus::Active);

        $academicClass = new AcademicClass();
        $classReflection = new \ReflectionClass($academicClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($academicClass, $this->testClassId);
        $academicClass->setInstitute($institute);
        $academicClass->setAcademicYear($academicYear);
        $academicClass->setName('Test Class');
        $academicClass->setCode('TC' . $unique);
        $academicClass->setStatus(ClassStatus::Active);

        $subject = new Subject();
        $subjectReflection = new \ReflectionClass($subject);
        $subjectProperty = $subjectReflection->getProperty('id');
        $subjectProperty->setAccessible(true);
        $subjectProperty->setValue($subject, $this->testSubjectId);
        $subject->setInstitute($institute);
        $subject->setName('Test Subject');
        $subject->setCode('TS' . $unique);
        $subject->setStatus(SubjectStatus::Active);

        $section = new Section();
        $sectionReflection = new \ReflectionClass($section);
        $sectionProperty = $sectionReflection->getProperty('id');
        $sectionProperty->setAccessible(true);
        $sectionProperty->setValue($section, $this->testSectionId);
        $section->setAcademicClass($academicClass);
        $section->setName('Test Section');
        $section->setCode('TSE' . $unique);
        $section->setStatus(SectionStatus::Active);

        $instituteUser = new User();
        $instituteUser->setEmail('institute-user-' . $unique . '@edupulse.ai');
        $instituteUser->setPassword(password_hash('InstituteUser123!', PASSWORD_BCRYPT));
        $instituteUser->setRoles([UserRole::Institute->value]);
        $instituteUser->setStatus(UserStatus::Active);
        $instituteUser->verify();
        $instituteUser->setInstitute($institute);

        $entityManager->persist($superAdmin);
        $entityManager->persist($institute);
        $entityManager->persist($academicYear);
        $entityManager->persist($academicClass);
        $entityManager->persist($subject);
        $entityManager->persist($section);
        $entityManager->persist($instituteUser);
        $entityManager->flush();

        $this->createdEmails = [
            'superadmin-test-' . $unique . '@edupulse.ai',
            'institute-user-' . $unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login($this->client, 'superadmin-test-' . $unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->instituteToken = $this->login($this->client, 'institute-user-' . $unique . '@edupulse.ai', 'InstituteUser123!');
    }

    protected function tearDown(): void
    {
        if (empty($this->createdEmails)) {
            parent::tearDown();
            return;
        }

        try {
            $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

            if ($this->testExaminationId) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM exam_subjects WHERE examination_id = :id',
                    ['id' => $this->testExaminationId->toRfc4122()]
                );
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM examinations WHERE id = :id',
                    ['id' => $this->testExaminationId->toRfc4122()]
                );
            }

            if ($this->testSectionId) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM sections WHERE id = :id',
                    ['id' => $this->testSectionId->toRfc4122()]
                );
            }

            if ($this->testSubjectId) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM subjects WHERE id = :id',
                    ['id' => $this->testSubjectId->toRfc4122()]
                );
            }

            if ($this->testClassId) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM classes WHERE id = :id',
                    ['id' => $this->testClassId->toRfc4122()]
                );
            }

            if ($this->testAcademicYearId) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM academic_years WHERE id = :id',
                    ['id' => $this->testAcademicYearId->toRfc4122()]
                );
            }

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

            if ($this->existingInstituteId) {
                $institute = $instituteRepo->find($this->existingInstituteId);
                if ($institute) {
                    $entityManager->remove($institute);
                }
            }

            $entityManager->flush();
        } catch (\Throwable $e) {
            // Ignore cleanup errors
        }

        $this->superAdminToken = null;
        $this->instituteToken = null;
        $this->testInstituteId = null;
        $this->existingInstituteId = null;
        $this->createdEmails = [];
        $this->testAcademicYearId = null;
        $this->testClassId = null;
        $this->testSubjectId = null;
        $this->testSectionId = null;
        $this->testExaminationId = null;

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

    public function testSuperAdminCanCreateExamination(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'instituteId' => (string) $this->testInstituteId,
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'name' => 'Final Examination',
            'code' => 'FE' . uniqid(),
            'description' => 'End of term examination',
            'startDate' => '2025-06-01',
            'endDate' => '2025-06-15',
            'status' => 'DRAFT',
        ]);

        $client->request('POST', '/api/v1/examinations', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        var_dump($response->getContent());
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Final Examination', $data['data']['name']);
        $this->assertArrayHasKey('id', $data['data']);
        $this->testExaminationId = Uuid::fromString($data['data']['id']);
    }

    public function testInstituteUserCanCreateExamination(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'name' => 'Mid Term Examination',
            'code' => 'MTE' . uniqid(),
            'startDate' => '2025-03-01',
            'endDate' => '2025-03-15',
            'status' => 'SCHEDULED',
        ]);

        $client->request('POST', '/api/v1/examinations', [], [], $this->getAuthHeaders($this->instituteToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->testExaminationId = Uuid::fromString($data['data']['id']);
    }

    public function testSuperAdminCanListExaminations(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/examinations', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertArrayHasKey('pagination', $data['data']);
        $this->assertIsArray($data['data']['items']);
    }

    public function testSuperAdminCanGetExaminationById(): void
    {
        $client = $this->client;

        $examinationId = $this->createTestExamination($this->superAdminToken);

        $client->request('GET', '/api/v1/examinations/' . $examinationId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Test Examination', $data['data']['name']);
    }

    public function testSuperAdminCanUpdateExamination(): void
    {
        $client = $this->client;

        $examinationId = $this->createTestExamination($this->superAdminToken);

        $payload = json_encode([
            'name' => 'Updated Examination',
            'status' => 'ONGOING',
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $examinationId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        var_dump($response->getContent());
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Updated Examination', $data['data']['name']);
        $this->assertSame('ONGOING', $data['data']['status']);
    }

    public function testSuperAdminCanDeleteExamination(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $examinationId = $this->createTestExamination($this->superAdminToken);

        $client->request('DELETE', '/api/v1/examinations/' . $examinationId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('archived', strtolower($data['message']));

        $refreshedExamination = $entityManager->getRepository(\App\Entity\Examination::class)->find($examinationId);
        $this->assertNotNull($refreshedExamination);
        $this->assertSame(\App\Enum\ExaminationStatus::Archived->value, $refreshedExamination->getStatus()->value);
    }

    public function testDuplicateCodeValidation(): void
    {
        $client = $this->client;

        $code = 'DUP' . uniqid();

        $payload1 = json_encode([
            'instituteId' => (string) $this->testInstituteId,
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'name' => 'First Exam',
            'code' => $code,
            'startDate' => '2025-06-01',
            'endDate' => '2025-06-15',
            'status' => 'DRAFT',
        ]);

        $client->request('POST', '/api/v1/examinations', [], [], $this->getAuthHeaders($this->superAdminToken), $payload1);
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        $payload2 = json_encode([
            'instituteId' => (string) $this->testInstituteId,
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'name' => 'Second Exam',
            'code' => $code,
            'startDate' => '2025-06-01',
            'endDate' => '2025-06-15',
            'status' => 'DRAFT',
        ]);

        $client->request('POST', '/api/v1/examinations', [], [], $this->getAuthHeaders($this->superAdminToken), $payload2);
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('code already exists', $data['message']);
    }

    public function testInvalidAcademicYear(): void
    {
        $client = $this->client;

        $invalidYearId = Uuid::v7();

        $payload = json_encode([
            'instituteId' => (string) $this->testInstituteId,
            'academicYearId' => (string) $invalidYearId,
            'academicClassId' => (string) $this->testClassId,
            'name' => 'Test Exam',
            'code' => 'TE' . uniqid(),
            'startDate' => '2025-06-01',
            'endDate' => '2025-06-15',
            'status' => 'DRAFT',
        ]);

        $client->request('POST', '/api/v1/examinations', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testInvalidClass(): void
    {
        $client = $this->client;

        $invalidClassId = Uuid::v7();

        $payload = json_encode([
            'instituteId' => (string) $this->testInstituteId,
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $invalidClassId,
            'name' => 'Test Exam',
            'code' => 'TE' . uniqid(),
            'startDate' => '2025-06-01',
            'endDate' => '2025-06-15',
            'status' => 'DRAFT',
        ]);

        $client->request('POST', '/api/v1/examinations', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testCrossInstituteAccessPrevention(): void
    {
        $client = $this->client;

        $otherInstitute = new Institute();
        $otherInstitute->setName('Other Institute');
        $otherInstitute->setCode('OTH' . uniqid());
        $otherInstitute->setEmail('other-' . uniqid() . '@edupulse.ai');
        $otherInstitute->setStatus(\App\Enum\InstituteStatus::Active);

        $reflection = new \ReflectionClass($otherInstitute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($otherInstitute, Uuid::v7());

        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        $entityManager->persist($otherInstitute);
        $entityManager->flush();

        $examinationId = $this->createTestExamination($this->superAdminToken, $otherInstitute->getId());

        $client->request('GET', '/api/v1/examinations/' . $examinationId, [], [], $this->getAuthHeaders($this->instituteToken));
        $this->assertSame(404, $client->getResponse()->getStatusCode());

        $entityManager->remove($otherInstitute);
        $entityManager->flush();
    }

    public function testInstituteUserCannotAccessOtherInstituteExamination(): void
    {
        $client = $this->client;

        $examinationId = $this->createTestExamination($this->superAdminToken);

        $client->request('PUT', '/api/v1/examinations/' . $examinationId, [], [], $this->getAuthHeaders($this->instituteToken), json_encode(['name' => 'Hacked']));
        $this->assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('DELETE', '/api/v1/examinations/' . $examinationId, [], [], $this->getAuthHeaders($this->instituteToken));
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testUnauthorizedRoleReceives403(): void
    {
        $client = $this->client;

        $studentEmail = 'student-test-' . uniqid() . '@edupulse.ai';

        $student = new User();
        $student->setEmail($studentEmail);
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();

        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        $entityManager->persist($student);
        $entityManager->flush();

        $studentToken = $this->login($client, $studentEmail, 'Student123!');
        var_dump($studentToken);

        $client->request('GET', '/api/v1/examinations', [], [], $this->getAuthHeaders($studentToken));
        $response = $client->getResponse();
        var_dump($response->getContent());
        $this->assertSame(403, $response->getStatusCode());

        $entityManager->getConnection()->executeStatement(
            'DELETE FROM users WHERE id = :id',
            ['id' => $student->getId()->toRfc4122()]
        );
    }

    public function testCreateExamSubject(): void
    {
        $client = $this->client;

        $examinationId = $this->createTestExamination($this->superAdminToken);

        $payload = json_encode([
            'subjectId' => (string) $this->testSubjectId,
            'examDate' => '2025-06-10',
            'startTime' => '09:00',
            'endTime' => '12:00',
            'maxMarks' => 100,
            'passingMarks' => 40,
            'status' => 'SCHEDULED',
        ]);

        $client->request('POST', '/api/v1/examinations/' . $examinationId . '/subjects', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('SCHEDULED', $data['data']['status']);
        $this->assertSame(100, $data['data']['maxMarks']);
    }

    public function testCreateExamSubjectWithInvalidSection(): void
    {
        $client = $this->client;

        $examinationId = $this->createTestExamination($this->superAdminToken);
        $invalidSectionId = Uuid::v7();

        $payload = json_encode([
            'subjectId' => (string) $this->testSubjectId,
            'sectionId' => (string) $invalidSectionId,
            'examDate' => '2025-06-10',
            'startTime' => '09:00',
            'endTime' => '12:00',
            'status' => 'DRAFT',
        ]);

        $client->request('POST', '/api/v1/examinations/' . $examinationId . '/subjects', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testCreateExamSubjectWithInvalidSchedule(): void
    {
        $client = $this->client;

        $examinationId = $this->createTestExamination($this->superAdminToken);

        $payload = json_encode([
            'subjectId' => (string) $this->testSubjectId,
            'examDate' => '2025-06-10',
            'startTime' => '12:00',
            'endTime' => '09:00',
            'status' => 'DRAFT',
        ]);

        $client->request('POST', '/api/v1/examinations/' . $examinationId . '/subjects', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testCreateExamSubjectWithInvalidMarks(): void
    {
        $client = $this->client;

        $examinationId = $this->createTestExamination($this->superAdminToken);

        $payload = json_encode([
            'subjectId' => (string) $this->testSubjectId,
            'examDate' => '2025-06-10',
            'startTime' => '09:00',
            'endTime' => '12:00',
            'maxMarks' => 100,
            'passingMarks' => 150,
            'status' => 'DRAFT',
        ]);

        $client->request('POST', '/api/v1/examinations/' . $examinationId . '/subjects', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testCreateExamSubjectWithDateOutsideExaminationRange(): void
    {
        $client = $this->client;

        $examinationId = $this->createTestExamination($this->superAdminToken);

        $payload = json_encode([
            'subjectId' => (string) $this->testSubjectId,
            'examDate' => '2025-07-20',
            'startTime' => '09:00',
            'endTime' => '12:00',
            'status' => 'DRAFT',
        ]);

        $client->request('POST', '/api/v1/examinations/' . $examinationId . '/subjects', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testUpdateExamSubject(): void
    {
        $client = $this->client;

        $examinationId = $this->createTestExamination($this->superAdminToken);
        $examSubjectId = $this->createTestExamSubject($examinationId, $this->superAdminToken);

        $payload = json_encode([
            'maxMarks' => 80,
            'passingMarks' => 32,
            'status' => 'ONGOING',
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $examinationId . '/subjects/' . $examSubjectId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame(80, $data['data']['maxMarks']);
        $this->assertSame('ONGOING', $data['data']['status']);
    }

    public function testDeleteExamSubject(): void
    {
        $client = $this->client;

        $examinationId = $this->createTestExamination($this->superAdminToken);
        $examSubjectId = $this->createTestExamSubject($examinationId, $this->superAdminToken);

        $client->request('DELETE', '/api/v1/examinations/' . $examinationId . '/subjects/' . $examSubjectId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testInvalidExaminationUuidReturnsNotFound(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/examinations/invalid-uuid', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testNonExistentExaminationIdReturnsNotFound(): void
    {
        $client = $this->client;

        $nonExistentId = Uuid::v7();

        $client->request('GET', '/api/v1/examinations/' . $nonExistentId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    private function createTestExamination(string $token, ?Uuid $instituteId = null): string
    {
        $client = $this->client;

        if ($instituteId === null) {
            $instituteId = $this->testInstituteId;
        }

        $payload = json_encode([
            'instituteId' => (string) $instituteId,
            'academicYearId' => (string) $this->testAcademicYearId,
            'academicClassId' => (string) $this->testClassId,
            'name' => 'Test Examination',
            'code' => 'TE' . uniqid(),
            'startDate' => '2025-06-01',
            'endDate' => '2025-06-15',
            'status' => 'DRAFT',
        ]);

        $client->request('POST', '/api/v1/examinations', [], [], $this->getAuthHeaders($token), $payload);
        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        return $data['data']['id'];
    }

    private function createTestExamSubject(string $examinationId, string $token): string
    {
        $client = $this->client;

        $payload = json_encode([
            'subjectId' => (string) $this->testSubjectId,
            'examDate' => '2025-06-10',
            'startTime' => '09:00',
            'endTime' => '12:00',
            'maxMarks' => 100,
            'passingMarks' => 40,
            'status' => 'SCHEDULED',
        ]);

        $client->request('POST', '/api/v1/examinations/' . $examinationId . '/subjects', [], [], $this->getAuthHeaders($token), $payload);
        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        return $data['data']['id'];
    }

    public function testArchivedExaminationCannotBeUpdated(): void
    {
        $client = $this->client;

        $examinationId = $this->createTestExamination($this->superAdminToken);

        $client->request('DELETE', '/api/v1/examinations/' . $examinationId, [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $payload = json_encode([
            'name' => 'Updated Archived Examination',
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $examinationId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('archived', strtolower($data['message']));
    }

    public function testArchivedExaminationCannotBeDeleted(): void
    {
        $client = $this->client;

        $examinationId = $this->createTestExamination($this->superAdminToken);

        $client->request('DELETE', '/api/v1/examinations/' . $examinationId, [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('DELETE', '/api/v1/examinations/' . $examinationId, [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('archived', strtolower($data['message']));
    }

    public function testArchivedExamSubjectCannotBeUpdated(): void
    {
        $client = $this->client;

        $examinationId = $this->createTestExamination($this->superAdminToken);
        $examSubjectId = $this->createTestExamSubject($examinationId, $this->superAdminToken);

        $client->request('DELETE', '/api/v1/examinations/' . $examinationId, [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $payload = json_encode([
            'maxMarks' => 200,
        ]);

        $client->request('PUT', '/api/v1/examinations/' . $examinationId . '/subjects/' . $examSubjectId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('archived', strtolower($data['message']));
    }

    public function testArchivedExamSubjectCannotBeDeleted(): void
    {
        $client = $this->client;

        $examinationId = $this->createTestExamination($this->superAdminToken);
        $examSubjectId = $this->createTestExamSubject($examinationId, $this->superAdminToken);

        $client->request('DELETE', '/api/v1/examinations/' . $examinationId, [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('DELETE', '/api/v1/examinations/' . $examinationId . '/subjects/' . $examSubjectId, [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('archived', strtolower($data['message']));
    }
}
