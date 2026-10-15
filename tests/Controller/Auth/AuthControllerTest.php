<?php

declare(strict_types=1);

namespace App\Tests\Controller\Auth;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\Institute;
use App\Entity\StudentEnrollment;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\InstituteStatus;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Service\Auth\GoogleAuthService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class AuthControllerTest extends WebTestCase
{
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
    private ?Uuid $testClassId = null;
    private ?Uuid $testClass2Id = null;
    private array $createdEmails = [];
    private array $mockGooglePayload = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        // Override GoogleAuthService with a mock
        $testCase = $this;
        $mockGoogleAuthService = $this->createMock(GoogleAuthService::class);
        $mockGoogleAuthService->method('verifyIdToken')
            ->willReturnCallback(function (string $idToken) use ($testCase) {
                $payload = $testCase->mockGooglePayload;
                if (empty($payload)) {
                    throw new \InvalidArgumentException('Invalid Google ID token');
                }
                
                // Perform same validation as real GoogleAuthService
                if (!isset($payload['email'])) {
                    throw new \InvalidArgumentException('Google ID token missing email claim');
                }
                
                if (!isset($payload['email_verified']) || !$payload['email_verified']) {
                    throw new \InvalidArgumentException('Google account email is not verified');
                }
                
                if (!isset($payload['iss']) || !in_array($payload['iss'], ['accounts.google.com', 'https://accounts.google.com'], true)) {
                    throw new \InvalidArgumentException('Invalid token issuer');
                }
                
                if (!isset($payload['aud']) || $payload['aud'] !== 'test-google-client-id.apps.googleusercontent.com') {
                    throw new \InvalidArgumentException('Invalid token audience');
                }
                
                if (!isset($payload['exp']) || $payload['exp'] < time()) {
                    throw new \InvalidArgumentException('Google ID token has expired');
                }
                
                return $payload;
            });
        $container->set(GoogleAuthService::class, $mockGoogleAuthService);

        $unique = uniqid();
        $this->testInstituteId = Uuid::v7();
        $this->testAcademicYearId = Uuid::v7();
        $this->testClassId = Uuid::v7();
        $this->testClass2Id = Uuid::v7();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Test Institute');
        $institute->setCode('TEST' . $unique);
        $institute->setEmail('test-' . $unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);

        $academicYear = new AcademicYear();
        $ayReflection = new \ReflectionClass($academicYear);
        $ayProperty = $ayReflection->getProperty('id');
        $ayProperty->setAccessible(true);
        $ayProperty->setValue($academicYear, $this->testAcademicYearId);
        $academicYear->setInstitute($institute);
        $academicYear->setName('Test Academic Year');
        $academicYear->setCode('AY' . $unique);
        $academicYear->setStartDate(new \DateTimeImmutable('2025-01-01'));
        $academicYear->setEndDate(new \DateTimeImmutable('2025-12-31'));
        $academicYear->setStatus(AcademicYearStatus::Active);
        $academicYear->setIsActive(true);

        $class1 = new AcademicClass();
        $classReflection = new \ReflectionClass($class1);
        $classProperty = $classReflection->getProperty('id');
        $classProperty->setAccessible(true);
        $classProperty->setValue($class1, $this->testClassId);
        $class1->setInstitute($institute);
        $class1->setAcademicYear($academicYear);
        $class1->setName('Class 1');
        $class1->setCode('C1' . $unique);
        $class1->setStatus(ClassStatus::Active);

        $class2 = new AcademicClass();
        $classProperty->setValue($class2, $this->testClass2Id);
        $class2->setInstitute($institute);
        $class2->setAcademicYear($academicYear);
        $class2->setName('Nursery');
        $class2->setCode('NUR' . $unique);
        $class2->setStatus(ClassStatus::Active);

        $entityManager->persist($institute);
        $entityManager->persist($academicYear);
        $entityManager->persist($class1);
        $entityManager->persist($class2);
        $entityManager->flush();
    }

    private function createStudent(string $email, AcademicClass $class, bool $isCurrent = true, string $status = StudentEnrollmentStatus::Active->value): User
    {
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $student = new User();
        $student->setEmail($email);
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $enrollment = new StudentEnrollment();
        $enrollment->setStudent($student);
        $enrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $enrollment->setAcademicClass($class);
        $enrollment->setAdmissionNumber('ADM-' . uniqid());
        $enrollment->setIsCurrent($isCurrent);
        $enrollment->setStatus(\App\Enum\StudentEnrollmentStatus::from($status));
        $enrollment->setInstituteId($this->testInstituteId);

        $entityManager->persist($student);
        $entityManager->persist($enrollment);
        $entityManager->flush();

        $this->createdEmails[] = $email;

        return $student;
    }

    private function login(string $email, string $password): string
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => $email,
            'password' => $password,
        ]));

        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);

        return $data['data']['accessToken'];
    }

    private function getAuthHeaders(string $token): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    private function setMockGooglePayload(array $payload): void
    {
        $this->mockGooglePayload = $payload;
    }

    public function testMeReturnsEnrollmentWithStatusAndClass(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $class1 = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);
        $student = $this->createStudent('student-me-test-' . uniqid() . '@edupulse.ai', $class1);
        $token = $this->login($student->getEmail(), 'Student123!');

        $client->request('GET', '/api/v1/auth/me', [], [], $this->getAuthHeaders($token));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('enrollments', $data['data']);
        $this->assertCount(1, $data['data']['enrollments']);

        $enrollment = $data['data']['enrollments'][0];
        $this->assertArrayHasKey('id', $enrollment);
        $this->assertArrayHasKey('status', $enrollment);
        $this->assertArrayHasKey('academicClass', $enrollment);

        $this->assertSame('ACTIVE', $enrollment['status']);
        $this->assertNotNull($enrollment['academicClass']);
        $this->assertSame((string) $this->testClassId, $enrollment['academicClass']['id']);
        $this->assertSame('Class 1', $enrollment['academicClass']['name']);
    }

    public function testMeReturnsMultipleEnrollmentsWithDifferentStatuses(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $class1 = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);
        $class2 = $entityManager->getRepository(AcademicClass::class)->find($this->testClass2Id);

        $email = 'student-multi-enrollment-' . uniqid() . '@edupulse.ai';

        // Create student with current enrollment in Class 1
        $student = $this->createStudent($email, $class1, true, StudentEnrollmentStatus::Active->value);

        // Add archived enrollment in Nursery
        $archivedEnrollment = new StudentEnrollment();
        $archivedEnrollment->setStudent($student);
        $archivedEnrollment->setAcademicYear($entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId));
        $archivedEnrollment->setAcademicClass($class2);
        $archivedEnrollment->setAdmissionNumber('ADM-OLD-' . uniqid());
        $archivedEnrollment->setIsCurrent(false);
        $archivedEnrollment->setStatus(\App\Enum\StudentEnrollmentStatus::Archived);
        $archivedEnrollment->setInstituteId($this->testInstituteId);

        $entityManager->persist($archivedEnrollment);
        $entityManager->flush();

        $token = $this->login($email, 'Student123!');

        $client->request('GET', '/api/v1/auth/me', [], [], $this->getAuthHeaders($token));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']['enrollments']);

        // Find enrollments by class name
        $activeEnrollment = null;
        $archivedEnrollmentResponse = null;
        foreach ($data['data']['enrollments'] as $enrollment) {
            if ($enrollment['academicClass']['name'] === 'Class 1') {
                $activeEnrollment = $enrollment;
            }
            if ($enrollment['academicClass']['name'] === 'Nursery') {
                $archivedEnrollmentResponse = $enrollment;
            }
        }

        $this->assertNotNull($activeEnrollment);
        $this->assertSame('ACTIVE', $activeEnrollment['status']);
        $this->assertSame((string) $this->testClassId, $activeEnrollment['academicClass']['id']);

        $this->assertNotNull($archivedEnrollmentResponse);
        $this->assertSame('ARCHIVED', $archivedEnrollmentResponse['status']);
        $this->assertSame((string) $this->testClass2Id, $archivedEnrollmentResponse['academicClass']['id']);
    }

    public function testMeDoesNotReturnOtherStudentsEnrollments(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $class1 = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);

        // Create first student
        $student1 = $this->createStudent('student1-me-test-' . uniqid() . '@edupulse.ai', $class1);

        // Create second student
        $student2 = $this->createStudent('student2-me-test-' . uniqid() . '@edupulse.ai', $class1);

        // Login as student 1
        $token = $this->login($student1->getEmail(), 'Student123!');

        $client->request('GET', '/api/v1/auth/me', [], [], $this->getAuthHeaders($token));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['data']['enrollments']);

        // Verify only student 1's enrollment is returned
        $this->assertNotSame((string) $student2->getId(), $data['data']['id']);
    }

    public function testGoogleLoginValidStudent(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $class1 = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);
        $email = 'google-student-' . uniqid() . '@edupulse.ai';
        $this->createStudent($email, $class1);

        $this->setMockGooglePayload([
            'email' => $email,
            'email_verified' => true,
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id.apps.googleusercontent.com',
            'exp' => time() + 3600,
        ]);

        $client->request('POST', '/api/v1/auth/google', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['idToken' => 'valid-token']));

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('accessToken', $data['data']);
        $this->assertArrayHasKey('refresh_token', $data['data']);
        $this->assertSame($email, $data['data']['user']['email']);
    }

    public function testGoogleLoginUnknownEmail(): void
    {
        $client = $this->client;

        $email = 'unknown-' . uniqid() . '@edupulse.ai';
        $this->setMockGooglePayload([
            'email' => $email,
            'email_verified' => true,
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id.apps.googleusercontent.com',
            'exp' => time() + 3600,
        ]);

        $client->request('POST', '/api/v1/auth/google', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['idToken' => 'valid-token']));

        $response = $client->getResponse();
        
        // Debug: print response content on failure
        $content = $response->getContent();
        if ($response->getStatusCode() !== 200) {
            echo "Response status: " . $response->getStatusCode() . "\n";
            echo "Response content: " . $content . "\n";
        }

        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('accessToken', $data['data']);
        $this->assertArrayHasKey('refresh_token', $data['data']);
        $this->assertSame($email, $data['data']['user']['email']);
        $this->assertSame([UserRole::Student->value], $data['data']['user']['roles']);
    }

    public function testGoogleLoginWrongRole(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $class1 = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);

        $teacher = new User();
        $teacher->setEmail('teacher-google-' . uniqid() . '@edupulse.ai');
        $teacher->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
        $teacher->setRoles([UserRole::Teacher->value]);
        $teacher->setStatus(UserStatus::Active);
        $teacher->verify();
        $teacher->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $entityManager->persist($teacher);
        $entityManager->flush();
        $this->createdEmails[] = $teacher->getEmail();

        $this->setMockGooglePayload([
            'email' => $teacher->getEmail(),
            'email_verified' => true,
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id.apps.googleusercontent.com',
            'exp' => time() + 3600,
        ]);

        $client->request('POST', '/api/v1/auth/google', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['idToken' => 'valid-token']));

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('student', strtolower($data['message']));
    }

    public function testGoogleLoginUnverifiedEmail(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $class1 = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);
        $email = 'unverified-google-' . uniqid() . '@edupulse.ai';
        $this->createStudent($email, $class1);

        $this->setMockGooglePayload([
            'email' => $email,
            'email_verified' => false,
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id.apps.googleusercontent.com',
            'exp' => time() + 3600,
        ]);

        $client->request('POST', '/api/v1/auth/google', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['idToken' => 'valid-token']));

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testGoogleLoginInvalidToken(): void
    {
        $client = $this->client;

        // Set empty payload to simulate invalid token
        $this->setMockGooglePayload([]);

        $client->request('POST', '/api/v1/auth/google', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['idToken' => 'invalid.token.string']));

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testGoogleLoginExpiredToken(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $class1 = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);
        $email = 'expired-google-' . uniqid() . '@edupulse.ai';
        $this->createStudent($email, $class1);

        $this->setMockGooglePayload([
            'email' => $email,
            'email_verified' => true,
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id.apps.googleusercontent.com',
            'exp' => time() - 3600,
        ]);

        $client->request('POST', '/api/v1/auth/google', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['idToken' => 'valid-token']));

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testGoogleLoginDisabledAccount(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $class1 = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);

        $student = new User();
        $student->setEmail('disabled-google-' . uniqid() . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Inactive);
        $student->verify();
        $student->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $entityManager->persist($student);
        $entityManager->flush();
        $this->createdEmails[] = $student->getEmail();

        $this->setMockGooglePayload([
            'email' => $student->getEmail(),
            'email_verified' => true,
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id.apps.googleusercontent.com',
            'exp' => time() + 3600,
        ]);

        $client->request('POST', '/api/v1/auth/google', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['idToken' => 'valid-token']));

        $response = $client->getResponse();
        $this->assertSame(403, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testGoogleLoginSoftDeletedAccount(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $class1 = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);

        $student = new User();
        $student->setEmail('deleted-google-' . uniqid() . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $reflection = new \ReflectionClass($student);
        $property = $reflection->getProperty('deletedAt');
        $property->setAccessible(true);
        $property->setValue($student, new \DateTimeImmutable());

        $entityManager->persist($student);
        $entityManager->flush();
        $this->createdEmails[] = $student->getEmail();

        $this->setMockGooglePayload([
            'email' => $student->getEmail(),
            'email_verified' => true,
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id.apps.googleusercontent.com',
            'exp' => time() + 3600,
        ]);

        $client->request('POST', '/api/v1/auth/google', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['idToken' => 'valid-token']));

        $response = $client->getResponse();
        $this->assertSame(401, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testGoogleLoginMissingIdToken(): void
    {
        $client = $this->client;

        $client->request('POST', '/api/v1/auth/google', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $response = $client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testGoogleLoginRegularLoginUnaffected(): void
    {
        $client = $this->client;
        $entityManager = $client->getContainer()->get('doctrine')->getManager();

        $class1 = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);
        $email = 'regular-login-' . uniqid() . '@edupulse.ai';
        $this->createStudent($email, $class1);

        $token = $this->login($email, 'Student123!');

        $this->assertNotEmpty($token);
    }

    protected function tearDown(): void
    {
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        foreach ($this->createdEmails as $email) {
            $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user) {
                $entityManager->remove($user);
            }
        }

        if ($this->testClass2Id) {
            $class2 = $entityManager->getRepository(AcademicClass::class)->find($this->testClass2Id);
            if ($class2) {
                $entityManager->remove($class2);
            }
        }
        if ($this->testClassId) {
            $class1 = $entityManager->getRepository(AcademicClass::class)->find($this->testClassId);
            if ($class1) {
                $entityManager->remove($class1);
            }
        }
        if ($this->testAcademicYearId) {
            $ay = $entityManager->getRepository(AcademicYear::class)->find($this->testAcademicYearId);
            if ($ay) {
                $entityManager->remove($ay);
            }
        }
        if ($this->testInstituteId) {
            $institute = $entityManager->getRepository(Institute::class)->find($this->testInstituteId);
            if ($institute) {
                $entityManager->remove($institute);
            }
        }

        $entityManager->flush();

        parent::tearDown();
    }
}
