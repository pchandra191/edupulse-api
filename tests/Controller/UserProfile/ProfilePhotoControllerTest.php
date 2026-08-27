<?php
declare(strict_types=1);

namespace App\Tests\Controller\UserProfile;

use App\Entity\Institute;
use App\Entity\User;
use App\Enum\InstituteStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

class ProfilePhotoControllerTest extends WebTestCase
{
    private ?KernelBrowser $client = null;
    private ?string $superAdminToken = null;
    private ?string $teacherAToken = null;
    private ?string $teacherBToken = null;
    private ?Uuid $testInstituteId = null;
    private ?Uuid $teacherAId = null;
    private ?Uuid $teacherBId = null;
    private array $createdEmails = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $unique = uniqid();
        $this->testInstituteId = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('sa-photo-test-' . $unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Photo Test Institute');
        $institute->setCode('PHOTO' . $unique);
        $institute->setEmail('photo-' . $unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);

        $teacherA = new User();
        $this->teacherAId = Uuid::v7();
        $teacherAReflection = new \ReflectionClass($teacherA);
        $teacherAProperty = $teacherAReflection->getProperty('id');
        $teacherAProperty->setAccessible(true);
        $teacherAProperty->setValue($teacherA, $this->teacherAId);
        $teacherA->setEmail('teacher-photo-a-' . $unique . '@edupulse.ai');
        $teacherA->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
        $teacherA->setRoles([UserRole::Teacher->value]);
        $teacherA->setStatus(UserStatus::Active);
        $teacherA->verify();
        $teacherA->setFirstName('Teacher A');
        $teacherA->setLastName('Photo');
        $teacherA->setInstitute($institute);

        $teacherB = new User();
        $this->teacherBId = Uuid::v7();
        $teacherBReflection = new \ReflectionClass($teacherB);
        $teacherBProperty = $teacherBReflection->getProperty('id');
        $teacherBProperty->setAccessible(true);
        $teacherBProperty->setValue($teacherB, $this->teacherBId);
        $teacherB->setEmail('teacher-photo-b-' . $unique . '@edupulse.ai');
        $teacherB->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
        $teacherB->setRoles([UserRole::Teacher->value]);
        $teacherB->setStatus(UserStatus::Active);
        $teacherB->verify();
        $teacherB->setFirstName('Teacher B');
        $teacherB->setLastName('Photo');
        $teacherB->setInstitute($institute);

        $entityManager->persist($superAdmin);
        $entityManager->persist($institute);
        $entityManager->persist($teacherA);
        $entityManager->persist($teacherB);
        $entityManager->flush();

        $this->createdEmails = [
            'sa-photo-test-' . $unique . '@edupulse.ai',
            'teacher-photo-a-' . $unique . '@edupulse.ai',
            'teacher-photo-b-' . $unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login('sa-photo-test-' . $unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->teacherAToken = $this->login('teacher-photo-a-' . $unique . '@edupulse.ai', 'Teacher123!');
        $this->teacherBToken = $this->login('teacher-photo-b-' . $unique . '@edupulse.ai', 'Teacher123!');
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
        }

        $this->superAdminToken = null;
        $this->teacherAToken = null;
        $this->teacherBToken = null;
        $this->testInstituteId = null;
        $this->teacherAId = null;
        $this->teacherBId = null;
        $this->createdEmails = [];

        parent::tearDown();
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

    private function authHeaders(string $token): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];
    }

    public function testTeacherCanUploadProfilePhoto(): void
    {
        $photo = $this->createTestImageFile('test_upload.png');

        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/profile-photo', [], ['file' => $photo], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('profile_photos/', $data['data']['profilePhotoReference']);
        $this->assertStringContainsString('.png', $data['data']['profilePhotoReference']);

        @unlink($photo->getPathname());
    }

    public function testTeacherCanReplaceExistingPhoto(): void
    {
        $photo1 = $this->createTestImageFile('test_replace_1.png');
        $photo2 = $this->createTestImageFile('test_replace_2.png');

        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/profile-photo', [], ['file' => $photo1], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $firstReference = $data['data']['profilePhotoReference'];

        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/profile-photo', [], ['file' => $photo2], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotSame($firstReference, $data['data']['profilePhotoReference']);

        @unlink($photo1->getPathname());
        @unlink($photo2->getPathname());
    }

    public function testTeacherCanRemoveProfilePhoto(): void
    {
        $photo = $this->createTestImageFile('test_remove.png');

        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/profile-photo', [], ['file' => $photo], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $this->client->request('DELETE', '/api/v1/users/' . $this->teacherAId . '/profile-photo', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/profile', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertNull($data['data']['profilePhotoReference']);

        @unlink($photo->getPathname());
    }

    public function testTeacherCanModifySameInstituteUserPhoto(): void
    {
        $photo = $this->createTestImageFile('test_same_institute.png');

        $this->client->request('POST', '/api/v1/users/' . $this->teacherBId . '/profile-photo', [], ['file' => $photo], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('profile_photos/', $data['data']['profilePhotoReference']);

        @unlink($photo->getPathname());
    }

    public function testSuperAdminCanModifyAnyUserPhoto(): void
    {
        $photo = $this->createTestImageFile('test_superadmin.png');

        $this->client->request('POST', '/api/v1/users/' . $this->teacherBId . '/profile-photo', [], ['file' => $photo], $this->authHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('profile_photos/', $data['data']['profilePhotoReference']);

        @unlink($photo->getPathname());
    }

    public function testInvalidFileTypeRejected(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'invalid_');
        file_put_contents($tempFile, 'This is not an image.');

        $photo = new UploadedFile(
            $tempFile,
            'invalid.txt',
            'text/plain',
            null,
            true,
        );

        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/profile-photo', [], ['file' => $photo], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode());

        @unlink($tempFile);
    }

    public function testOversizedFileRejected(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'large_');
        file_put_contents($tempFile, str_repeat('x', 3 * 1024 * 1024));

        $photo = new UploadedFile(
            $tempFile,
            'large.jpg',
            'image/jpeg',
            null,
            true,
        );

        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/profile-photo', [], ['file' => $photo], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode());

        @unlink($tempFile);
    }

    public function testRetrieveProfileWithPhotoReference(): void
    {
        $photo = $this->createTestImageFile('test_retrieve.png');

        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/profile-photo', [], ['file' => $photo], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $reference = $data['data']['profilePhotoReference'];

        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/profile', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame($reference, $data['data']['profilePhotoReference']);

        @unlink($photo->getPathname());
    }

    private function createTestImageFile(string $filename): UploadedFile
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'photo_') . '.png';

        $image = imagecreatetruecolor(10, 10);
        $color = imagecolorallocate($image, 255, 0, 0);
        imagefilledrectangle($image, 0, 0, 10, 10, $color);
        imagepng($image, $tempFile);
        imagedestroy($image);

        return new UploadedFile(
            $tempFile,
            $filename,
            'image/png',
            null,
            true,
        );
    }
}
