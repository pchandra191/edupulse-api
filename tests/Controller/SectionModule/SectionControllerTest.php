<?php
declare(strict_types=1);

namespace App\Tests\Controller\SectionModule;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\Institute;
use App\Entity\Section;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\InstituteStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class SectionControllerTest extends WebTestCase
{
    private ?string $superAdminToken = null;
    private ?string $instituteToken = null;
    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
    private ?Uuid $testClassId = null;
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private array $createdEmails = [];
    private ?Uuid $otherInstituteId = null;
    private ?Uuid $otherClassId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $unique = uniqid();
        $this->testInstituteId = Uuid::v7();
        $this->testAcademicYearId = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-section-test-' . $unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for Sections');
        $institute->setCode('TESTSEC' . $unique);
        $institute->setEmail('test-institute-section-' . $unique . '@edupulse.ai');
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

        $instituteUser = new User();
        $instituteUser->setEmail('institute-section-test-' . $unique . '@edupulse.ai');
        $instituteUser->setPassword(password_hash('InstituteUser123!', PASSWORD_BCRYPT));
        $instituteUser->setRoles([UserRole::Institute->value]);
        $instituteUser->setStatus(UserStatus::Active);
        $instituteUser->verify();
        $instituteUser->setInstitute($institute);

        $entityManager->persist($superAdmin);
        $entityManager->persist($institute);
        $entityManager->persist($academicYear);
        $entityManager->persist($academicClass);
        $entityManager->persist($instituteUser);
        $entityManager->flush();

        $this->testInstituteId = $institute->getId();

        $this->createdEmails = [
            'superadmin-section-test-' . $unique . '@edupulse.ai',
            'institute-section-test-' . $unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login($this->client, 'superadmin-section-test-' . $unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->instituteToken = $this->login($this->client, 'institute-section-test-' . $unique . '@edupulse.ai', 'InstituteUser123!');
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
        $this->otherInstituteId = null;
        $this->otherClassId = null;
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

    private function createOtherInstituteClass(): AcademicClass
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

        $otherInstitute = new Institute();
        $this->otherInstituteId = Uuid::v7();
        $instituteReflection = new \ReflectionClass($otherInstitute);
        $instituteProperty = $instituteReflection->getProperty('id');
        $instituteProperty->setAccessible(true);
        $instituteProperty->setValue($otherInstitute, $this->otherInstituteId);
        $otherInstitute->setName('Other Institute');
        $otherInstitute->setCode('OTHR' . uniqid());
        $otherInstitute->setEmail('other-' . uniqid() . '@edupulse.ai');
        $otherInstitute->setStatus(InstituteStatus::Active);

        $otherYear = new AcademicYear();
        $otherYear->setInstitute($otherInstitute);
        $otherYear->setName('Other Year');
        $otherYear->setCode('OY' . uniqid());
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
        $otherClass->setName('Other Class');
        $otherClass->setCode('OCLS' . uniqid());
        $otherClass->setStatus(ClassStatus::Active);

        $entityManager->persist($otherInstitute);
        $entityManager->persist($otherYear);
        $entityManager->persist($otherClass);
        $entityManager->flush();

        return $otherClass;
    }

    public function testSuperAdminCanListSections(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/sections', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertArrayHasKey('pagination', $data['data']);
        $this->assertIsArray($data['data']['items']);
    }

    public function testSuperAdminCanGetSectionById(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $section = new Section();
        $section->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $section->setName('Test Section');
        $section->setCode('TS01');
        $section->setStatus(\App\Enum\SectionStatus::Active);
        $entityManager->persist($section);
        $entityManager->flush();

        $client->request('GET', '/api/v1/sections/' . $section->getId(), [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Test Section', $data['data']['name']);
        $this->assertSame('TS01', $data['data']['code']);
        $this->assertSame('ACTIVE', $data['data']['status']);
        $this->assertArrayHasKey('institute', $data['data']);
        file_put_contents('F:/Projects/React/edupulse-api/tmp_institute_response.txt', 'INSTITUTE: ' . print_r($data['data']['institute'], true) . PHP_EOL);
        $this->assertSame((string) $this->testInstituteId, $data['data']['institute']['id']);
        $this->assertSame('Test Institute for Sections', $data['data']['institute']['name']);
        $this->assertArrayHasKey('academicYear', $data['data']);
        $this->assertSame((string) $this->testAcademicYearId, $data['data']['academicYear']['id']);
        $this->assertArrayHasKey('academicClass', $data['data']);
        $this->assertSame((string) $this->testClassId, $data['data']['academicClassId']);
    }

    public function testSuperAdminCanCreateSection(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'academicClassId' => (string) $this->testClassId,
            'name' => 'Super Admin Section',
            'code' => 'SAS01',
            'capacity' => 30,
            'description' => 'Created by super admin',
            'status' => 'ACTIVE',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/sections', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Super Admin Section', $data['data']['name']);
        $this->assertSame('SAS01', $data['data']['code']);
        $this->assertSame('ACTIVE', $data['data']['status']);
        $this->assertSame((string) $this->testClassId, $data['data']['academicClassId']);
        $this->assertArrayHasKey('institute', $data['data']);
        $this->assertSame((string) $this->testInstituteId, $data['data']['institute']['id']);
        $this->assertSame('Test Institute for Sections', $data['data']['institute']['name']);
        $this->assertArrayHasKey('academicYear', $data['data']);
        $this->assertSame((string) $this->testAcademicYearId, $data['data']['academicYear']['id']);
        $this->assertArrayHasKey('academicClass', $data['data']);
    }

    public function testSuperAdminCanUpdateSection(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $section = new Section();
        $section->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $section->setName('Original Name');
        $section->setCode('ORIG01');
        $section->setCapacity(20);
        $section->setStatus(\App\Enum\SectionStatus::Active);
        $entityManager->persist($section);
        $entityManager->flush();

        $payload = json_encode([
            'name' => 'Updated Name',
            'capacity' => 40,
            'status' => 'INACTIVE',
        ]);

        $client->request('PUT', '/api/v1/sections/' . $section->getId(), [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Updated Name', $data['data']['name']);
        $this->assertSame(40, $data['data']['capacity']);
        $this->assertSame('INACTIVE', $data['data']['status']);
        $this->assertSame('ORIG01', $data['data']['code']);
        $this->assertArrayHasKey('institute', $data['data']);
        $this->assertSame((string) $this->testInstituteId, $data['data']['institute']['id']);
        $this->assertSame('Test Institute for Sections', $data['data']['institute']['name']);
        $this->assertArrayHasKey('academicYear', $data['data']);
        $this->assertSame((string) $this->testAcademicYearId, $data['data']['academicYear']['id']);
    }

    public function testSuperAdminCanDeleteSection(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $section = new Section();
        $section->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $section->setName('To Delete');
        $section->setCode('DEL01');
        $section->setStatus(\App\Enum\SectionStatus::Active);
        $entityManager->persist($section);
        $entityManager->flush();

        $client->request('DELETE', '/api/v1/sections/' . $section->getId(), [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('archived', strtolower($data['message']));

        $refreshedSection = $entityManager->getRepository(Section::class)->find($section->getId());
        $this->assertNotNull($refreshedSection);
        $this->assertSame(\App\Enum\SectionStatus::Archived->value, $refreshedSection->getStatus()->value);
    }

    public function testInstituteUserCanListOwnSections(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $section = new Section();
        $section->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $section->setName('Own Section');
        $section->setCode('OWN01');
        $section->setStatus(\App\Enum\SectionStatus::Active);
        $entityManager->persist($section);
        $entityManager->flush();

        $client->request('GET', '/api/v1/sections', [], [], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['data']['items']);
    }

    public function testInstituteUserCannotListAnotherInstitutesSections(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherClass = $this->createOtherInstituteClass();

        $otherSection = new Section();
        $otherSection->setAcademicClass($otherClass);
        $otherSection->setName('Other Section');
        $otherSection->setCode('OTH01');
        $otherSection->setStatus(\App\Enum\SectionStatus::Active);
        $entityManager->persist($otherSection);
        $entityManager->flush();

        $client->request('GET', '/api/v1/sections', [], [], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['data']['items']);

        foreach ($data['data']['items'] as $item) {
            $this->assertNotSame((string) $otherSection->getId(), $item['id']);
        }
    }

    public function testInstituteUserCannotGetAnotherInstitutesSection(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherClass = $this->createOtherInstituteClass();

        $otherSection = new Section();
        $otherSection->setAcademicClass($otherClass);
        $otherSection->setName('Other Section');
        $otherSection->setCode('OTH02');
        $otherSection->setStatus(\App\Enum\SectionStatus::Active);
        $entityManager->persist($otherSection);
        $entityManager->flush();

        $client->request('GET', '/api/v1/sections/' . $otherSection->getId(), [], [], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testInstituteUserCannotUpdateAnotherInstitutesSection(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherClass = $this->createOtherInstituteClass();

        $otherSection = new Section();
        $otherSection->setAcademicClass($otherClass);
        $otherSection->setName('Other Section');
        $otherSection->setCode('OTH03');
        $otherSection->setStatus(\App\Enum\SectionStatus::Active);
        $entityManager->persist($otherSection);
        $entityManager->flush();

        $payload = json_encode(['name' => 'Hacked Name']);

        $client->request('PUT', '/api/v1/sections/' . $otherSection->getId(), [], [], $this->getAuthHeaders($this->instituteToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testInstituteUserCannotDeleteAnotherInstitutesSection(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherClass = $this->createOtherInstituteClass();

        $otherSection = new Section();
        $otherSection->setAcademicClass($otherClass);
        $otherSection->setName('Other Section');
        $otherSection->setCode('OTH04');
        $otherSection->setStatus(\App\Enum\SectionStatus::Active);
        $entityManager->persist($otherSection);
        $entityManager->flush();

        $client->request('DELETE', '/api/v1/sections/' . $otherSection->getId(), [], [], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testInstituteUserCannotCreateSectionUsingClassFromAnotherInstitute(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherClass = $this->createOtherInstituteClass();

        $payload = json_encode([
            'academicClassId' => (string) $otherClass->getId(),
            'name' => 'Cross Institute Section',
            'code' => 'CROSS01',
            'status' => 'ACTIVE',
        ]);

        $client->request('POST', '/api/v1/sections', [], [], $this->getAuthHeaders($this->instituteToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success'] ?? false ? false : true);
        $this->assertStringContainsString('does not belong', $data['message']);
    }

    public function testInvalidAcademicClassIdReturnsNotFound(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'academicClassId' => (string) Uuid::v7(),
            'name' => 'Invalid Class Section',
            'code' => 'INV01',
            'status' => 'ACTIVE',
        ]);

        $client->request('POST', '/api/v1/sections', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testInvalidStatusReturnsValidationError(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $section = new Section();
        $section->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $section->setName('Status Test');
        $section->setCode('ST01');
        $section->setStatus(\App\Enum\SectionStatus::Active);
        $entityManager->persist($section);
        $entityManager->flush();

        $payload = json_encode([
            'status' => 'INVALID_STATUS',
        ]);

        $client->request('PUT', '/api/v1/sections/' . $section->getId(), [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('status', $data['errors']);
    }

    public function testInvalidCapacityReturnsValidationError(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $section = new Section();
        $section->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $section->setName('Capacity Test');
        $section->setCode('CT01');
        $section->setStatus(\App\Enum\SectionStatus::Active);
        $entityManager->persist($section);
        $entityManager->flush();

        $payload = json_encode([
            'capacity' => -5,
        ]);

        $client->request('PUT', '/api/v1/sections/' . $section->getId(), [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('capacity', $data['errors']);
    }

    public function testDuplicateCodeReturnsError(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $section1 = new Section();
        $section1->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $section1->setName('Section One');
        $section1->setCode('DUP01');
        $section1->setStatus(\App\Enum\SectionStatus::Active);
        $entityManager->persist($section1);
        $entityManager->flush();

        $payload = json_encode([
            'academicClassId' => (string) $this->testClassId,
            'name' => 'Duplicate Code Section',
            'code' => 'DUP01',
            'status' => 'ACTIVE',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/sections', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('already exists', strtolower($data['message']));
    }

    public function testUnauthorizedRoleReceives403(): void
    {
        $client = $this->client;

        $studentEmail = 'student-section-test-' . uniqid() . '@edupulse.ai';

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

        $client->request('GET', '/api/v1/sections', [], [], $this->getAuthHeaders($studentToken));

        $response = $client->getResponse();
        $this->assertSame(404, $response->getStatusCode());

        $entityManager->getConnection()->executeStatement(
            'DELETE FROM users WHERE id = :id',
            ['id' => $student->getId()->toRfc4122()]
        );
    }
}
