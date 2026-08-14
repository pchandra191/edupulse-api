<?php
declare(strict_types=1);

namespace App\Tests\Controller\User;

use App\Entity\Institute;
use App\Entity\User;
use App\Enum\InstituteStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class UserControllerTest extends WebTestCase
{
    private ?string $superAdminToken = null;
    private ?string $instituteToken = null;
    private ?Uuid $testInstituteId = null;
    private ?Uuid $otherInstituteId = null;
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private array $createdEmails = [];
    private ?Uuid $teacherAId = null;
    private ?Uuid $teacherBId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $unique = uniqid();
        $this->testInstituteId = Uuid::v7();
        $this->otherInstituteId = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('superadmin-user-test-' . $unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute A');
        $institute->setCode('TESTA' . $unique);
        $institute->setEmail('test-institute-a-' . $unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);

        $otherInstitute = new Institute();
        $otherReflection = new \ReflectionClass($otherInstitute);
        $otherProperty = $otherReflection->getProperty('id');
        $otherProperty->setAccessible(true);
        $otherProperty->setValue($otherInstitute, $this->otherInstituteId);
        $otherInstitute->setName('Test Institute B');
        $otherInstitute->setCode('TESTB' . $unique);
        $otherInstitute->setEmail('test-institute-b-' . $unique . '@edupulse.ai');
        $otherInstitute->setStatus(InstituteStatus::Active);

        $instituteUser = new User();
        $instituteUser->setEmail('institute-user-test-' . $unique . '@edupulse.ai');
        $instituteUser->setPassword(password_hash('InstituteUser123!', PASSWORD_BCRYPT));
        $instituteUser->setRoles([UserRole::Institute->value]);
        $instituteUser->setStatus(UserStatus::Active);
        $instituteUser->verify();
        $instituteUser->setInstitute($institute);

        $teacherA = new User();
        $this->teacherAId = Uuid::v7();
        $teacherAReflection = new \ReflectionClass($teacherA);
        $teacherAProperty = $teacherAReflection->getProperty('id');
        $teacherAProperty->setAccessible(true);
        $teacherAProperty->setValue($teacherA, $this->teacherAId);
        $teacherA->setEmail('teacher-a-' . $unique . '@edupulse.ai');
        $teacherA->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
        $teacherA->setRoles([UserRole::Teacher->value]);
        $teacherA->setStatus(UserStatus::Active);
        $teacherA->verify();
        $teacherA->setFirstName('Teacher A');
        $teacherA->setLastName('From A');
        $teacherA->setInstitute($institute);

        $teacherB = new User();
        $this->teacherBId = Uuid::v7();
        $teacherBReflection = new \ReflectionClass($teacherB);
        $teacherBProperty = $teacherBReflection->getProperty('id');
        $teacherBProperty->setAccessible(true);
        $teacherBProperty->setValue($teacherB, $this->teacherBId);
        $teacherB->setEmail('teacher-b-' . $unique . '@edupulse.ai');
        $teacherB->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
        $teacherB->setRoles([UserRole::Teacher->value]);
        $teacherB->setStatus(UserStatus::Active);
        $teacherB->verify();
        $teacherB->setFirstName('Teacher B');
        $teacherB->setLastName('From B');
        $teacherB->setInstitute($otherInstitute);

        $entityManager->persist($superAdmin);
        $entityManager->persist($institute);
        $entityManager->persist($otherInstitute);
        $entityManager->persist($instituteUser);
        $entityManager->persist($teacherA);
        $entityManager->persist($teacherB);
        $entityManager->flush();

        $this->createdEmails = [
            'superadmin-user-test-' . $unique . '@edupulse.ai',
            'institute-user-test-' . $unique . '@edupulse.ai',
            'teacher-a-' . $unique . '@edupulse.ai',
            'teacher-b-' . $unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login($this->client, 'superadmin-user-test-' . $unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->instituteToken = $this->login($this->client, 'institute-user-test-' . $unique . '@edupulse.ai', 'InstituteUser123!');
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
        $this->otherInstituteId = null;
        $this->teacherAId = null;
        $this->teacherBId = null;
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

    public function testSuperAdminCanFilterTeachersByInstituteA(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/users?role=ROLE_TEACHER&instituteId=' . $this->testInstituteId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['data']['items']);

        foreach ($data['data']['items'] as $item) {
            $this->assertSame((string) $this->testInstituteId, $item['institute']['id']);
        }

        $foundTeacherA = false;
        $foundTeacherB = false;
        foreach ($data['data']['items'] as $item) {
            if ($item['id'] === (string) $this->teacherAId) {
                $foundTeacherA = true;
            }
            if ($item['id'] === (string) $this->teacherBId) {
                $foundTeacherB = true;
            }
        }
        $this->assertTrue($foundTeacherA, 'Teacher A should be in institute A results');
        $this->assertFalse($foundTeacherB, 'Teacher B should NOT be in institute A results');
    }

    public function testSuperAdminCanFilterTeachersByInstituteB(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/users?role=ROLE_TEACHER&instituteId=' . $this->otherInstituteId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['data']['items']);

        foreach ($data['data']['items'] as $item) {
            $this->assertSame((string) $this->otherInstituteId, $item['institute']['id']);
        }

        $foundTeacherA = false;
        $foundTeacherB = false;
        foreach ($data['data']['items'] as $item) {
            if ($item['id'] === (string) $this->teacherAId) {
                $foundTeacherA = true;
            }
            if ($item['id'] === (string) $this->teacherBId) {
                $foundTeacherB = true;
            }
        }
        $this->assertFalse($foundTeacherA, 'Teacher A should NOT be in institute B results');
        $this->assertTrue($foundTeacherB, 'Teacher B should be in institute B results');
    }

    public function testSuperAdminCanFilterTeachersWithoutInstitute(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/users?role=ROLE_TEACHER', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['data']['items']);

        $foundTeacherA = false;
        $foundTeacherB = false;
        foreach ($data['data']['items'] as $item) {
            if ($item['id'] === (string) $this->teacherAId) {
                $foundTeacherA = true;
            }
            if ($item['id'] === (string) $this->teacherBId) {
                $foundTeacherB = true;
            }
        }
        $this->assertTrue($foundTeacherA, 'Teacher A should be in unfiltered results');
        $this->assertTrue($foundTeacherB, 'Teacher B should be in unfiltered results');
    }

    public function testInstituteUserCannotAccessOtherInstituteTeachers(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/users?role=ROLE_TEACHER&instituteId=' . $this->otherInstituteId, [], [], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['data']['items']);

        foreach ($data['data']['items'] as $item) {
            $this->assertSame((string) $this->testInstituteId, $item['institute']['id']);
        }

        $foundTeacherB = false;
        foreach ($data['data']['items'] as $item) {
            if ($item['id'] === (string) $this->teacherBId) {
                $foundTeacherB = true;
            }
        }
        $this->assertFalse($foundTeacherB, 'Institute user must not see teachers from other institute');
    }

    public function testInstituteUserSeesOnlyOwnInstituteTeachers(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/users?role=ROLE_TEACHER', [], [], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['data']['items']);

        foreach ($data['data']['items'] as $item) {
            $this->assertSame((string) $this->testInstituteId, $item['institute']['id']);
        }

        $foundTeacherA = false;
        $foundTeacherB = false;
        foreach ($data['data']['items'] as $item) {
            if ($item['id'] === (string) $this->teacherAId) {
                $foundTeacherA = true;
            }
            if ($item['id'] === (string) $this->teacherBId) {
                $foundTeacherB = true;
            }
        }
        $this->assertTrue($foundTeacherA, 'Institute user should see own teachers');
        $this->assertFalse($foundTeacherB, 'Institute user should not see other institute teachers');
    }

    public function testRoleAndInstituteIdFilterWithPagination(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/users?role=ROLE_TEACHER&instituteId=' . $this->testInstituteId . '&page=1&limit=10', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('pagination', $data['data']);
        $this->assertSame(1, $data['data']['pagination']['page']);
        $this->assertSame(10, $data['data']['pagination']['limit']);
        $this->assertGreaterThanOrEqual(1, $data['data']['pagination']['totalItems']);
    }

    public function testSuperAdminCanFilterByInvalidInstitute(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/users?role=ROLE_TEACHER&instituteId=' . Uuid::v7(), [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame(0, $data['data']['pagination']['totalItems']);
    }
}
