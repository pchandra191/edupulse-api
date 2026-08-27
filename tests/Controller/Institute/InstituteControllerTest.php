<?php
declare(strict_types=1);

namespace App\Tests\Controller\Institute;

use App\Entity\Institute;
use App\Entity\User;
use App\Enum\InstituteStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class InstituteControllerTest extends WebTestCase
{
    private ?string $superAdminToken = null;
    private ?string $instituteToken = null;
    private ?Uuid $testInstituteId = null;
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

        $instituteUser = new User();
        $instituteUser->setEmail('institute-user-' . $unique . '@edupulse.ai');
        $instituteUser->setPassword(password_hash('InstituteUser123!', PASSWORD_BCRYPT));
        $instituteUser->setRoles([UserRole::Institute->value]);
        $instituteUser->setStatus(UserStatus::Active);
        $instituteUser->verify();
        $instituteUser->setInstitute($institute);

        $entityManager->persist($superAdmin);
        $entityManager->persist($institute);
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

    private function createValidImage(string $path, string $format): void
    {
        $image = imagecreatetruecolor(1, 1);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagesetpixel($image, 0, 0, $white);

        match (strtolower($format)) {
            'png' => imagepng($image, $path),
            'jpg', 'jpeg' => imagejpeg($image, $path, 85),
            'webp' => imagewebp($image, $path, 80),
            default => throw new \InvalidArgumentException('Unsupported format: ' . $format),
        };

        imagedestroy($image);
    }

    public function testSuperAdminCanListInstitutes(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/institutes', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertArrayHasKey('pagination', $data['data']);
        $this->assertIsArray($data['data']['items']);
    }

    public function testSuperAdminCanGetInstituteById(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/institutes/' . $this->testInstituteId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Test Institute', $data['data']['name']);
        $this->assertStringStartsWith('TEST', $data['data']['code']);
    }

    public function testSuperAdminCanUpdateInstituteById(): void
    {
        $client = $this->client;

        $unique = uniqid();
        $payload = json_encode([
            'name' => 'Updated Test Institute ' . $unique,
            'email' => 'updated-test-' . $unique . '@edupulse.ai',
            'phone' => '1234567890',
        ]);

        $client->request('PUT', '/api/v1/institutes/' . $this->testInstituteId, [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Updated Test Institute ' . $unique, $data['data']['name']);
        $this->assertSame('updated-test-' . $unique . '@edupulse.ai', $data['data']['email']);
    }

    public function testSuperAdminCanUpdateDifferentInstitute(): void
    {
        $client = $this->client;

        $unique = uniqid();
        $existingInstitute = new Institute();
        $existingInstitute->setName('Existing Institute');
        $existingInstitute->setCode('EXIST' . $unique);
        $existingInstitute->setEmail('existing-' . $unique . '@edupulse.ai');
        $existingInstitute->setStatus(InstituteStatus::Active);

        $reflection = new \ReflectionClass($existingInstitute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($existingInstitute, Uuid::v7());

        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        $entityManager->persist($existingInstitute);
        $entityManager->flush();

        $this->existingInstituteId = $existingInstitute->getId();

        $payload = json_encode([
            'name' => 'Super Admin Updated Name',
            'email' => 'existing-' . $unique . '@edupulse.ai',
        ]);

        $client->request('PUT', '/api/v1/institutes/' . $existingInstitute->getId(), [], [], $this->getAuthHeaders($this->superAdminToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Super Admin Updated Name', $data['data']['name']);
    }

    public function testInstituteUserCanContinueUsingProfileEndpoint(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/institute/profile', [], [], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Test Institute', $data['data']['name']);
    }

    public function testInstituteUserCannotUpdateAnotherInstituteViaSuperAdminEndpoint(): void
    {
        $client = $this->client;

        $payload = json_encode([
            'name' => 'Hacked Name',
        ]);

        $client->request('PUT', '/api/v1/institutes/' . $this->testInstituteId, [], [], $this->getAuthHeaders($this->instituteToken), $payload);

        $response = $client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testInvalidInstituteUuidReturnsNotFound(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/institutes/invalid-uuid', [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testNonExistentInstituteIdReturnsNotFound(): void
    {
        $client = $this->client;

        $nonExistentId = Uuid::v7();

        $client->request('GET', '/api/v1/institutes/' . $nonExistentId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
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

        $client->request('GET', '/api/v1/institutes', [], [], $this->getAuthHeaders($studentToken));

        $response = $client->getResponse();
        $this->assertSame(403, $response->getStatusCode());

        $entityManager->getConnection()->executeStatement(
            'DELETE FROM users WHERE id = :id',
            ['id' => $student->getId()->toRfc4122()]
        );
    }

    public function testInstituteWithoutLogoReturnsNull(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/institutes/' . $this->testInstituteId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNull($data['data']['logo']);
    }

    public function testInstituteAdminCanUploadLogo(): void
    {
        $client = $this->client;

        $tempFile = tempnam(sys_get_temp_dir(), 'logo') . '.png';
        $this->createValidImage($tempFile, 'png');

        $client->request('POST', '/api/v1/institutes/' . $this->testInstituteId . '/logo', [], ['logo' => new \Symfony\Component\HttpFoundation\File\UploadedFile($tempFile, 'logo.png', 'image/png', null, true)], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotNull($data['data']['logo']);
        $this->assertStringContainsString('.jpg', $data['data']['logo']);

        @unlink($tempFile);
    }

    public function testSuperAdminCanUploadLogo(): void
    {
        $client = $this->client;

        $tempFile = tempnam(sys_get_temp_dir(), 'logo') . '.jpg';
        $this->createValidImage($tempFile, 'jpg');

        $client->request('POST', '/api/v1/institutes/' . $this->testInstituteId . '/logo', [], ['logo' => new \Symfony\Component\HttpFoundation\File\UploadedFile($tempFile, 'logo.jpg', 'image/jpeg', null, true)], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotNull($data['data']['logo']);
        $this->assertStringContainsString('.jpg', $data['data']['logo']);

        @unlink($tempFile);
    }

    public function testUnauthorizedRoleCannotUploadLogo(): void
    {
        $client = $this->client;

        $studentEmail = 'student-logo-test-' . uniqid() . '@edupulse.ai';

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

        $tempFile = tempnam(sys_get_temp_dir(), 'logo') . '.png';
        $this->createValidImage($tempFile, 'png');

        $client->request('POST', '/api/v1/institutes/' . $this->testInstituteId . '/logo', [], ['logo' => new \Symfony\Component\HttpFoundation\File\UploadedFile($tempFile, 'logo.png', 'image/png', null, true)], $this->getAuthHeaders($studentToken));

        $response = $client->getResponse();
        $this->assertSame(403, $response->getStatusCode());

        @unlink($tempFile);

        $entityManager->getConnection()->executeStatement(
            'DELETE FROM users WHERE id = :id',
            ['id' => $student->getId()->toRfc4122()]
        );
    }

    public function testInvalidMimeTypeRejected(): void
    {
        $client = $this->client;

        $tempFile = tempnam(sys_get_temp_dir(), 'logo') . '.exe';
        file_put_contents($tempFile, 'fake-executable-content');

        $client->request('POST', '/api/v1/institutes/' . $this->testInstituteId . '/logo', [], ['logo' => new \Symfony\Component\HttpFoundation\File\UploadedFile($tempFile, 'logo.exe', 'application/octet-stream', null, true)], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(422, $response->getStatusCode());

        @unlink($tempFile);
    }

    public function testOversizedImageRejected(): void
    {
        $client = $this->client;

        $tempFile = tempnam(sys_get_temp_dir(), 'logo') . '.png';
        file_put_contents($tempFile, str_repeat('x', 6 * 1024 * 1024));

        $client->request('POST', '/api/v1/institutes/' . $this->testInstituteId . '/logo', [], ['logo' => new \Symfony\Component\HttpFoundation\File\UploadedFile($tempFile, 'logo.png', 'image/png', null, true)], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(422, $response->getStatusCode());

        @unlink($tempFile);
    }

    public function testValidPngAccepted(): void
    {
        $client = $this->client;

        $tempFile = tempnam(sys_get_temp_dir(), 'logo') . '.png';
        $this->createValidImage($tempFile, 'png');

        $client->request('POST', '/api/v1/institutes/' . $this->testInstituteId . '/logo', [], ['logo' => new \Symfony\Component\HttpFoundation\File\UploadedFile($tempFile, 'logo.png', 'image/png', null, true)], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('.jpg', $data['data']['logo']);

        @unlink($tempFile);
    }

    public function testValidJpegAccepted(): void
    {
        $client = $this->client;

        $tempFile = tempnam(sys_get_temp_dir(), 'logo') . '.jpg';
        $this->createValidImage($tempFile, 'jpg');

        $client->request('POST', '/api/v1/institutes/' . $this->testInstituteId . '/logo', [], ['logo' => new \Symfony\Component\HttpFoundation\File\UploadedFile($tempFile, 'logo.jpg', 'image/jpeg', null, true)], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('.jpg', $data['data']['logo']);

        @unlink($tempFile);
    }

    public function testValidWebpAccepted(): void
    {
        $client = $this->client;

        $tempFile = tempnam(sys_get_temp_dir(), 'logo') . '.webp';
        $this->createValidImage($tempFile, 'webp');

        $client->request('POST', '/api/v1/institutes/' . $this->testInstituteId . '/logo', [], ['logo' => new \Symfony\Component\HttpFoundation\File\UploadedFile($tempFile, 'logo.webp', 'image/webp', null, true)], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('.jpg', $data['data']['logo']);

        @unlink($tempFile);
    }

    public function testExistingLogoCanBeReplaced(): void
    {
        $client = $this->client;

        $tempFile1 = tempnam(sys_get_temp_dir(), 'logo') . '.png';
        $this->createValidImage($tempFile1, 'png');

        $client->request('POST', '/api/v1/institutes/' . $this->testInstituteId . '/logo', [], ['logo' => new \Symfony\Component\HttpFoundation\File\UploadedFile($tempFile1, 'logo1.png', 'image/png', null, true)], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $firstLogo = json_decode($response->getContent(), true)['data']['logo'];
        $this->assertStringContainsString('.jpg', $firstLogo);

        $tempFile2 = tempnam(sys_get_temp_dir(), 'logo') . '.jpg';
        $this->createValidImage($tempFile2, 'jpg');

        $client->request('POST', '/api/v1/institutes/' . $this->testInstituteId . '/logo', [], ['logo' => new \Symfony\Component\HttpFoundation\File\UploadedFile($tempFile2, 'logo2.jpg', 'image/jpeg', null, true)], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $secondLogo = json_decode($response->getContent(), true)['data']['logo'];

        $this->assertStringContainsString('.jpg', $secondLogo);

        @unlink($tempFile1);
        @unlink($tempFile2);
    }

    public function testLogoCanBeRemoved(): void
    {
        $client = $this->client;

        $tempFile = tempnam(sys_get_temp_dir(), 'logo') . '.png';
        $this->createValidImage($tempFile, 'png');

        $client->request('POST', '/api/v1/institutes/' . $this->testInstituteId . '/logo', [], ['logo' => new \Symfony\Component\HttpFoundation\File\UploadedFile($tempFile, 'logo.png', 'image/png', null, true)], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $client->request('DELETE', '/api/v1/institutes/' . $this->testInstituteId . '/logo', [], [], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNull($data['data']['logo']);

        @unlink($tempFile);
    }

    public function testInstituteResponseIncludesLogoReference(): void
    {
        $client = $this->client;

        $tempFile = tempnam(sys_get_temp_dir(), 'logo') . '.png';
        $this->createValidImage($tempFile, 'png');

        $client->request('POST', '/api/v1/institutes/' . $this->testInstituteId . '/logo', [], ['logo' => new \Symfony\Component\HttpFoundation\File\UploadedFile($tempFile, 'logo.png', 'image/png', null, true)], $this->getAuthHeaders($this->instituteToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $client->request('GET', '/api/v1/institutes/' . $this->testInstituteId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotNull($data['data']['logo']);
        $this->assertStringContainsString('/uploads/institutes/', $data['data']['logo']);

        @unlink($tempFile);
    }

    public function testLogoRemainsOptional(): void
    {
        $client = $this->client;

        $client->request('GET', '/api/v1/institutes/' . $this->testInstituteId, [], [], $this->getAuthHeaders($this->superAdminToken));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNull($data['data']['logo']);
    }
}
