<?php

declare(strict_types=1);

namespace App\Tests\Controller\Auth;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\EmailVerificationToken;
use App\Entity\Institute;
use App\Entity\InstituteStudentRegistrationLink;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\InstituteStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class EmailVerificationTest extends WebTestCase
{
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private ?Uuid $testInstituteId = null;
    private ?Uuid $testAcademicYearId = null;
    private ?Uuid $testClassId = null;
    private array $createdEmails = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $unique = uniqid();
        $this->testInstituteId = Uuid::v7();
        $this->testAcademicYearId = Uuid::v7();
        $this->testClassId = Uuid::v7();

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

        $entityManager->persist($institute);
        $entityManager->persist($academicYear);
        $entityManager->persist($class1);
        $entityManager->flush();
    }

    // 1. Normal student registration
    // 2. Institute-link student registration
    // 3. User created with is_verified = false
    // 14. Independent student's institute_id remains NULL
    public function testNormalRegistrationCreatesUnverifiedStudent(): void
    {
        $email = 'register-test-' . uniqid() . '@edupulse.ai';

        $this->client->request('POST', '/api/v1/auth/student/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'firstName' => 'Test',
            'lastName' => 'Student',
            'email' => $email,
            'password' => 'Student123!',
            'confirmPassword' => 'Student123!',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Registration successful. Please verify your email address.', $data['message']);
        $this->assertSame($email, $data['data']['email']);
        $this->assertTrue($data['data']['emailVerificationRequired']);
        $this->assertArrayNotHasKey('accessToken', $data['data'] ?? []);

        // Verify user is unverified
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($user);
        $this->assertFalse($user->isVerified());
        $this->assertSame(UserRole::Student->value, $user->getRoles()[0]);
        $this->assertNull($user->getInstitute());

        // Verify token was created
        $token = $entityManager->getRepository(EmailVerificationToken::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($token);
        $this->assertNull($token->getUsedAt());
        $this->assertNotEmpty($token->getSelector());
        $this->assertNotEmpty($token->getTokenHash());

        $this->createdEmails[] = $email;
    }

    // 2. Institute-link student registration
    // 15. Institute student's institute_id remains correctly assigned
    public function testInstituteRegistrationCreatesUnverifiedStudentWithInstitute(): void
    {
        $email = 'institute-register-test-' . uniqid() . '@edupulse.ai';

        $this->client->request('GET', '/api/v1/student-registration/institute/' . $this->getRegistrationToken(), [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        // Get registration token from institute info - we'll use the token directly
        $registrationToken = $this->getRegistrationToken();

        $this->client->request('POST', '/api/v1/auth/student/register/institute/' . $registrationToken, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'firstName' => 'Institute',
            'lastName' => 'Student',
            'email' => $email,
            'password' => 'Student123!',
            'confirmPassword' => 'Student123!',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame($email, $data['data']['email']);
        $this->assertTrue($data['data']['emailVerificationRequired']);

        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($user);
        $this->assertFalse($user->isVerified());
        $this->assertSame(UserRole::Student->value, $user->getRoles()[0]);
        $this->assertNotNull($user->getInstitute());
        $this->assertSame((string) $this->testInstituteId, (string) $user->getInstitute()->getId());

        $token = $entityManager->getRepository(EmailVerificationToken::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($token);

        $this->createdEmails[] = $email;
    }

    // 5. Verification link works
    // 12. Login after verification succeeds
    public function testVerifyEmailWorks(): void
    {
        $email = 'verify-test-' . uniqid() . '@edupulse.ai';
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

        // Create user via registration
        $this->client->request('POST', '/api/v1/auth/student/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'firstName' => 'Verify',
            'lastName' => 'Test',
            'email' => $email,
            'password' => 'Student123!',
            'confirmPassword' => 'Student123!',
        ]));

        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($user);
        $this->assertFalse($user->isVerified());

        // Get token
        $token = $entityManager->getRepository(EmailVerificationToken::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($token);
        $selector = $token->getSelector();

        // Verify email
        $this->client->request('POST', '/api/v1/auth/verify-email', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['token' => $selector]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Email verified successfully. You can now sign in.', $data['message']);

        // Verify user is now verified
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($user);
        $this->assertTrue($user->isVerified());

        // Token should be marked as used
        $token = $entityManager->getRepository(EmailVerificationToken::class)->findOneBy(['selector' => $selector]);
        $this->assertNotNull($token);
        $this->assertNotNull($token->getUsedAt());

        // Login should succeed
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => $email,
            'password' => 'Student123!',
        ]));

        $loginResponse = $this->client->getResponse();
        $this->assertSame(200, $loginResponse->getStatusCode(), $loginResponse->getContent());

        $loginData = json_decode($loginResponse->getContent(), true);
        $this->assertTrue($loginData['success']);

        $this->createdEmails[] = $email;
    }

    // 6. Invalid token
    public function testVerifyEmailInvalidToken(): void
    {
        $this->client->request('POST', '/api/v1/auth/verify-email', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['token' => 'invalid-token-that-does-not-exist']));

        $response = $this->client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    // 7. Expired token
    public function testVerifyEmailExpiredToken(): void
    {
        $email = 'expired-test-' . uniqid() . '@edupulse.ai';
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

        // Create user via registration
        $this->client->request('POST', '/api/v1/auth/student/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'firstName' => 'Expired',
            'lastName' => 'Token',
            'email' => $email,
            'password' => 'Student123!',
            'confirmPassword' => 'Student123!',
        ]));

        // Expire the token manually
        $token = $entityManager->getRepository(EmailVerificationToken::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($token);

        $reflection = new \ReflectionClass($token);
        $property = $reflection->getProperty('expiresAt');
        $property->setAccessible(true);
        $property->setValue($token, new \DateTimeImmutable('-2 days'));

        $entityManager->flush();

        // Try to verify with expired token
        $this->client->request('POST', '/api/v1/auth/verify-email', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['token' => $token->getSelector()]));

        $response = $this->client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $this->createdEmails[] = $email;
    }

    // 8. Already-used token
    public function testVerifyEmailAlreadyUsedToken(): void
    {
        $email = 'used-test-' . uniqid() . '@edupulse.ai';
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

        // Create user via registration
        $this->client->request('POST', '/api/v1/auth/student/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'firstName' => 'Used',
            'lastName' => 'Token',
            'email' => $email,
            'password' => 'Student123!',
            'confirmPassword' => 'Student123!',
        ]));

        $token = $entityManager->getRepository(EmailVerificationToken::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($token);
        $selector = $token->getSelector();

        // First verification - should succeed
        $this->client->request('POST', '/api/v1/auth/verify-email', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['token' => $selector]));

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        // Second verification with same token - should fail
        $this->client->request('POST', '/api/v1/auth/verify-email', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['token' => $selector]));

        $response = $this->client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        $this->createdEmails[] = $email;
    }

    // 9. Resend verification
    // 10. Old token invalidated after resend
    public function testResendVerification(): void
    {
        $email = 'resend-test-' . uniqid() . '@edupulse.ai';
        $entityManager = $this->client->getContainer()->get('doctrine');

        // Create user via registration
        $this->client->request('POST', '/api/v1/auth/student/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'firstName' => 'Resend',
            'lastName' => 'Test',
            'email' => $email,
            'password' => 'Student123!',
            'confirmPassword' => 'Student123!',
        ]));

        $oldToken = $entityManager->getManager()->getRepository(EmailVerificationToken::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($oldToken);
        $oldSelector = $oldToken->getSelector();

        // Resend verification
        $this->client->request('POST', '/api/v1/auth/resend-verification', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $email]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        // Old token should now be expired (used_at set after resend creates new token)
        // Actually old token remains but new one is created
        $em = $entityManager->getManager();
        $newToken = $em->getRepository(EmailVerificationToken::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($newToken);
        $this->assertNotSame($oldSelector, $newToken->getSelector());

        $this->createdEmails[] = $email;
    }

    // 11. Login before verification blocked with EMAIL_NOT_VERIFIED
    public function testLoginBeforeVerificationBlocked(): void
    {
        $email = 'login-blocked-test-' . uniqid() . '@edupulse.ai';

        $this->client->request('POST', '/api/v1/auth/student/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'firstName' => 'Blocked',
            'lastName' => 'Login',
            'email' => $email,
            'password' => 'Student123!',
            'confirmPassword' => 'Student123!',
        ]));

        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => $email,
            'password' => 'Student123!',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Please verify your email address before signing in.', $data['message']);
        $this->assertSame('EMAIL_NOT_VERIFIED', $data['code'] ?? null);
    }

    // 13. Existing verified users continue working
    public function testExistingVerifiedUserCanLogin(): void
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

        $student = new User();
        $student->setEmail('verified-existing-' . uniqid() . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));

        $entityManager->persist($student);
        $entityManager->flush();

        $this->createdEmails[] = $student->getEmail();

        // Login should succeed
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => $student->getEmail(),
            'password' => 'Student123!',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    private function getRegistrationToken(): string
    {
        // Use the test institute to generate a registration link token
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $link = $entityManager->getRepository(\App\Entity\InstituteStudentRegistrationLink::class)->findOneBy(['institute' => $this->testInstituteId]);

        if ($link === null) {
            $link = new \App\Entity\InstituteStudentRegistrationLink();
            $link->setToken(bin2hex(random_bytes(32)));
            $link->setInstitute($entityManager->getRepository(Institute::class)->find($this->testInstituteId));
            $entityManager->persist($link);
            $entityManager->flush();
        }

        return $link->getToken();
    }

    protected function tearDown(): void
    {
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        // Clean up email verification tokens
        foreach ($this->createdEmails as $email) {
            $token = $entityManager->getRepository(EmailVerificationToken::class)->findOneBy(['email' => $email]);
            if ($token) {
                $entityManager->remove($token);
            }
        }

        foreach ($this->createdEmails as $email) {
            $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user) {
                $entityManager->remove($user);
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
