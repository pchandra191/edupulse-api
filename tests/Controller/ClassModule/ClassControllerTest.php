<?php
declare(strict_types=1);

namespace App\Tests\Controller\ClassModule;

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

class ClassControllerTest extends WebTestCase
{
    private ?string $superAdminToken = null;
    private ?string $instituteToken = null;
    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
    private ?Uuid $testClassId = null;
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private array $createdEmails = [];
    private string $unique = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $this->unique = uniqid();
        $this->testInstituteId = Uuid::v7();
        $this->testAcademicYearId = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-class-test-' . $this->unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute for Classes');
        $institute->setCode('TESTCLS' . $this->unique);
        $institute->setEmail('test-institute-class-' . $this->unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);

        $academicYear = new AcademicYear();
        $ayReflection = new \ReflectionClass($academicYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($academicYear, $this->testAcademicYearId);
        $academicYear->setInstitute($institute);
        $academicYear->setName('Test Academic Year ' . $this->unique);
        $academicYear->setCode('AY' . $this->unique);
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
        $academicClass->setName('Test Class ' . $this->unique);
        $academicClass->setCode('CLS' . $this->unique);
        $academicClass->setStatus(ClassStatus::Active);

        $instituteUser = new User();
        $instituteUser->setEmail('institute-class-test-' . $this->unique . '@edupulse.ai');
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

        $this->createdEmails = [
            'superadmin-class-test-' . $this->unique . '@edupulse.ai',
            'institute-class-test-' . $this->unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login($this->client, 'superadmin-class-test-' . $this->unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->instituteToken = $this->login($this->client, 'institute-class-test-' . $this->unique . '@edupulse.ai', 'InstituteUser123!');
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
            // Ignore cleanup errors
        }

        $this->superAdminToken = null;
        $this->instituteToken = null;
        $this->testInstituteId = null;
        $this->testAcademicYearId = null;
        $this->testClassId = null;
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

    public function testSuperAdminCanListClasses(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/classes', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertIsArray($data['data']['items']);
    }

    public function testArchiveClassArchivesAllChildSections(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $section1 = new Section();
        $section1->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $section1->setName('Section One');
        $section1->setCode('SEC01');
        $section1->setStatus(\App\Enum\SectionStatus::Active);
        $entityManager->persist($section1);

        $section2 = new Section();
        $section2->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $section2->setName('Section Two');
        $section2->setCode('SEC02');
        $section2->setStatus(\App\Enum\SectionStatus::Active);
        $entityManager->persist($section2);

        $entityManager->flush();

        $client->request('DELETE', '/api/v1/classes/' . $this->testClassId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('archived', strtolower($data['message']));

        $refreshedClass = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);
        $this->assertNotNull($refreshedClass);
        $this->assertSame(ClassStatus::Archived->value, $refreshedClass->getStatus()->value);

        $refreshedSection1 = $entityManager->getRepository(Section::class)->find($section1->getId());
        $this->assertNotNull($refreshedSection1);
        $this->assertSame(\App\Enum\SectionStatus::Archived->value, $refreshedSection1->getStatus()->value);

        $refreshedSection2 = $entityManager->getRepository(Section::class)->find($section2->getId());
        $this->assertNotNull($refreshedSection2);
        $this->assertSame(\App\Enum\SectionStatus::Archived->value, $refreshedSection2->getStatus()->value);
    }

    public function testArchiveClassWithZeroSectionsWorks(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $client->request('DELETE', '/api/v1/classes/' . $this->testClassId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('archived', strtolower($data['message']));

        $refreshedClass = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);
        $this->assertNotNull($refreshedClass);
        $this->assertSame(ClassStatus::Archived->value, $refreshedClass->getStatus()->value);
    }

    public function testArchiveClassIsAtomic(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $section1 = new Section();
        $section1->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $section1->setName('Atomic Section 1');
        $section1->setCode('ATM01');
        $section1->setStatus(\App\Enum\SectionStatus::Active);
        $entityManager->persist($section1);

        $section2 = new Section();
        $section2->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $section2->setName('Atomic Section 2');
        $section2->setCode('ATM02');
        $section2->setStatus(\App\Enum\SectionStatus::Active);
        $entityManager->persist($section2);

        $entityManager->flush();

        $client->request('DELETE', '/api/v1/classes/' . $this->testClassId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $refreshedClass = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);
        $this->assertSame(ClassStatus::Archived->value, $refreshedClass->getStatus()->value);

        $refreshedSection1 = $entityManager->getRepository(Section::class)->find($section1->getId());
        $this->assertSame(\App\Enum\SectionStatus::Archived->value, $refreshedSection1->getStatus()->value);

        $refreshedSection2 = $entityManager->getRepository(Section::class)->find($section2->getId());
        $this->assertSame(\App\Enum\SectionStatus::Archived->value, $refreshedSection2->getStatus()->value);
    }

    public function testExistingDataRemainsIntactAfterClassArchive(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $section = new Section();
        $section->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $section->setName('Historical Section');
        $section->setCode('HIST01');
        $section->setStatus(\App\Enum\SectionStatus::Active);
        $entityManager->persist($section);
        $entityManager->flush();

        $client->request('DELETE', '/api/v1/classes/' . $this->testClassId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $refreshedClass = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);
        $this->assertNotNull($refreshedClass);
        $this->assertSame((string) $this->testAcademicYearId, (string) $refreshedClass->getAcademicYear()->getId());
        $this->assertSame((string) $this->testInstituteId, (string) $refreshedClass->getInstitute()->getId());

        $refreshedSection = $entityManager->getRepository(Section::class)->find($section->getId());
        $this->assertNotNull($refreshedSection);
        $this->assertSame((string) $this->testClassId, (string) $refreshedSection->getAcademicClass()->getId());
    }

    public function testCannotCreateClassUnderArchivedAcademicYear(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $year = $entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId);
        $year->setStatus(AcademicYearStatus::Archived);
        $entityManager->flush();

        $payload = json_encode([
            'academicYearId' => (string) $this->testAcademicYearId,
            'name' => 'New Class',
            'code' => 'NEWCLS' . uniqid(),
            'status' => 'ACTIVE',
            'instituteId' => (string) $this->testInstituteId,
        ]);

        $client->request('POST', '/api/v1/classes', [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        file_put_contents('F:/Projects/React/edupulse-api/tmp_debug.txt', 'STATUS: ' . $response->getStatusCode() . PHP_EOL . 'CONTENT: ' . $response->getContent() . PHP_EOL);
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('archived', strtolower($data['message']));
    }

    public function testArchivedClassExcludedFromDefaultList(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $section = new Section();
        $section->setAcademicClass($entityManager->getRepository(AcademicClass::class)->find($this->testClassId));
        $section->setName('List Test Section');
        $section->setCode('LST01');
        $section->setStatus(\App\Enum\SectionStatus::Active);
        $entityManager->persist($section);
        $entityManager->flush();

        $client->request('DELETE', '/api/v1/classes/' . $this->testClassId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $client->request('GET', '/api/v1/classes', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        foreach ($data['data']['items'] as $item) {
            $this->assertNotSame((string) $this->testClassId, $item['id']);
        }
    }

    public function testArchivedClassCanBeExplicitlyListed(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $client->request('DELETE', '/api/v1/classes/' . $this->testClassId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $client->request('GET', '/api/v1/classes?status=ARCHIVED', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        $found = false;
        foreach ($data['data']['items'] as $item) {
            if ($item['id'] === (string) $this->testClassId) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Archived class should be returned when explicitly requested.');
    }

    public function testInstituteUserCannotArchiveAnotherInstitutesClass(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $otherInstitute = new Institute();
        $otherInstituteId = Uuid::v7();
        $reflection = new \ReflectionClass($otherInstitute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($otherInstitute, $otherInstituteId);
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

        $otherClass = new AcademicClass();
        $otherClassId = Uuid::v7();
        $classReflection = new \ReflectionClass($otherClass);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($otherClass, $otherClassId);
        $otherClass->setInstitute($otherInstitute);
        $otherClass->setAcademicYear($otherYear);
        $otherClass->setName('Other Class');
        $otherClass->setCode('OCLS' . uniqid());
        $otherClass->setStatus(ClassStatus::Active);

        $entityManager->persist($otherInstitute);
        $entityManager->persist($otherYear);
        $entityManager->persist($otherClass);
        $entityManager->flush();

        $client->request('DELETE', '/api/v1/classes/' . $otherClass->getId(), [], [], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(404, $response->getStatusCode());

        $refreshedClass = $entityManager->getRepository(AcademicClass::class)->find($otherClass->getId());
        $this->assertSame(ClassStatus::Active->value, $refreshedClass->getStatus()->value);
    }

    public function testCannotReactivateArchivedClass(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $client->request('DELETE', '/api/v1/classes/' . $this->testClassId, [], [], $this->getAuthHeaders($this->superAdminToken));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $payload = json_encode([
            'name' => 'Reactivated Class',
            'code' => 'REACT' . uniqid(),
            'status' => 'ACTIVE',
        ]);

        $client->request('PUT', '/api/v1/classes/' . $this->testClassId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('archived', strtolower($data['message']));

        $refreshedClass = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);
        $this->assertSame(ClassStatus::Archived->value, $refreshedClass->getStatus()->value);
    }
}
