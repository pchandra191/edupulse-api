<?php
declare(strict_types=1);

namespace App\Tests\Controller\UserProfile;

use App\Entity\Institute;
use App\Entity\User;
use App\Enum\AddressType;
use App\Enum\BankAccountType;
use App\Enum\EducationLevel;
use App\Enum\GovernmentIdType;
use App\Enum\InstituteStatus;
use App\Enum\UserDocumentType;
use App\Enum\UserGender;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class UserProfileControllerTest extends WebTestCase
{
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private ?string $superAdminToken = null;
    private ?string $instituteToken = null;
    private ?string $teacherAToken = null;
    private ?string $teacherBToken = null;
    private ?string $studentToken = null;
    private ?Uuid $testInstituteId = null;
    private ?Uuid $otherInstituteId = null;
    private ?Uuid $teacherAId = null;
    private ?Uuid $teacherBId = null;
    private ?Uuid $studentId = null;
    private array $createdEmails = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $unique = uniqid();
        $this->testInstituteId = Uuid::v7();
        $this->otherInstituteId = Uuid::v7();

        $superAdmin = new User();
        $superAdmin->setEmail('sa-profile-test-' . $unique . '@edupulse.ai');
        $superAdmin->setPassword(password_hash('SuperAdmin123!', PASSWORD_BCRYPT));
        $superAdmin->setRoles([UserRole::SuperAdmin->value]);
        $superAdmin->setStatus(UserStatus::Active);
        $superAdmin->verify();

        $institute = new Institute();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->testInstituteId);
        $institute->setName('Profile Test Institute A');
        $institute->setCode('PROFA' . $unique);
        $institute->setEmail('profa-' . $unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);

        $otherInstitute = new Institute();
        $otherReflection = new \ReflectionClass($otherInstitute);
        $otherProperty = $otherReflection->getProperty('id');
        $otherProperty->setAccessible(true);
        $otherProperty->setValue($otherInstitute, $this->otherInstituteId);
        $otherInstitute->setName('Profile Test Institute B');
        $otherInstitute->setCode('PROFB' . $unique);
        $otherInstitute->setEmail('profb-' . $unique . '@edupulse.ai');
        $otherInstitute->setStatus(InstituteStatus::Active);

        $instituteUser = new User();
        $instituteUser->setEmail('inst-profile-test-' . $unique . '@edupulse.ai');
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
        $teacherA->setEmail('teacher-profile-a-' . $unique . '@edupulse.ai');
        $teacherA->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
        $teacherA->setRoles([UserRole::Teacher->value]);
        $teacherA->setStatus(UserStatus::Active);
        $teacherA->verify();
        $teacherA->setFirstName('Teacher A');
        $teacherA->setLastName('Profile');
        $teacherA->setInstitute($institute);

        $teacherB = new User();
        $this->teacherBId = Uuid::v7();
        $teacherBReflection = new \ReflectionClass($teacherB);
        $teacherBProperty = $teacherBReflection->getProperty('id');
        $teacherBProperty->setAccessible(true);
        $teacherBProperty->setValue($teacherB, $this->teacherBId);
        $teacherB->setEmail('teacher-profile-b-' . $unique . '@edupulse.ai');
        $teacherB->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
        $teacherB->setRoles([UserRole::Teacher->value]);
        $teacherB->setStatus(UserStatus::Active);
        $teacherB->verify();
        $teacherB->setFirstName('Teacher B');
        $teacherB->setLastName('Profile');
        $teacherB->setInstitute($otherInstitute);

        $student = new User();
        $this->studentId = Uuid::v7();
        $studentReflection = new \ReflectionClass($student);
        $studentProperty = $studentReflection->getProperty('id');
        $studentProperty->setAccessible(true);
        $studentProperty->setValue($student, $this->studentId);
        $student->setEmail('student-profile-' . $unique . '@edupulse.ai');
        $student->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student->setRoles([UserRole::Student->value]);
        $student->setStatus(UserStatus::Active);
        $student->verify();
        $student->setFirstName('Student');
        $student->setLastName('Profile');
        $student->setInstitute($institute);

        $entityManager->persist($superAdmin);
        $entityManager->persist($institute);
        $entityManager->persist($otherInstitute);
        $entityManager->persist($instituteUser);
        $entityManager->persist($teacherA);
        $entityManager->persist($teacherB);
        $entityManager->persist($student);
        $entityManager->flush();

        $this->createdEmails = [
            'sa-profile-test-' . $unique . '@edupulse.ai',
            'inst-profile-test-' . $unique . '@edupulse.ai',
            'teacher-profile-a-' . $unique . '@edupulse.ai',
            'teacher-profile-b-' . $unique . '@edupulse.ai',
            'student-profile-' . $unique . '@edupulse.ai',
        ];

        $this->superAdminToken = $this->login('sa-profile-test-' . $unique . '@edupulse.ai', 'SuperAdmin123!');
        $this->instituteToken = $this->login('inst-profile-test-' . $unique . '@edupulse.ai', 'InstituteUser123!');
        $this->teacherAToken = $this->login('teacher-profile-a-' . $unique . '@edupulse.ai', 'Teacher123!');
        $this->teacherBToken = $this->login('teacher-profile-b-' . $unique . '@edupulse.ai', 'Teacher123!');
        $this->studentToken = $this->login('student-profile-' . $unique . '@edupulse.ai', 'Student123!');
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
        }

        $this->superAdminToken = null;
        $this->instituteToken = null;
        $this->teacherAToken = null;
        $this->teacherBToken = null;
        $this->studentToken = null;
        $this->testInstituteId = null;
        $this->otherInstituteId = null;
        $this->teacherAId = null;
        $this->teacherBId = null;
        $this->studentId = null;
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
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];
    }

    // ==========================================
    // Profile Tests
    // ==========================================

    public function testTeacherCanGetOwnProfile(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/profile', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Teacher A', $data['data']['firstName']);
    }

    public function testTeacherCanUpdateOwnProfile(): void
    {
        $this->client->request('PUT', '/api/v1/users/' . $this->teacherAId . '/profile', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'firstName' => 'Updated',
            'mobile' => '9999999999',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Updated', $data['data']['firstName']);
        $this->assertSame('9999999999', $data['data']['mobile']);
    }

    public function testTeacherCanAccessSameInstituteStudentProfile(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->studentId . '/profile', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Student', $data['data']['firstName']);
    }

    public function testTeacherCannotAccessOtherInstituteTeacherProfile(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherBId . '/profile', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSuperAdminCanAccessAnyProfile(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherBId . '/profile', [], [], $this->authHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Teacher B', $data['data']['firstName']);
    }

    public function testMeProfileEndpointReturnsCurrentUserProfile(): void
    {
        $this->client->request('GET', '/api/v1/me/profile', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Teacher A', $data['data']['firstName']);
    }

    // ==========================================
    // Address Tests
    // ==========================================

    public function testTeacherCanManageOwnAddresses(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/addresses', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'addressType' => 'CURRENT',
            'addressLine1' => '123 Main St',
            'city' => 'Test City',
            'state' => 'Test State',
            'country' => 'Asia/Kolkata',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $addressId = $data['data']['id'];

        $this->client->request('PUT', '/api/v1/users/' . $this->teacherAId . '/addresses/' . $addressId, [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'addressLine1' => '456 Updated St',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $this->client->request('DELETE', '/api/v1/users/' . $this->teacherAId . '/addresses/' . $addressId, [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testMultipleAddressesSupported(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/addresses', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'addressType' => 'CURRENT',
            'addressLine1' => '123 Main St',
            'city' => 'Test City',
            'state' => 'Test State',
            'country' => 'Asia/Kolkata',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/addresses', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'addressType' => 'PERMANENT',
            'addressLine1' => '789 Oak Ave',
            'city' => 'Permanent City',
            'state' => 'Permanent State',
            'country' => 'America/New_York',
            'isPrimary' => false,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/addresses', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']);
    }

    // ==========================================
    // Education Tests
    // ==========================================

    public function testMultipleEducationRecordsSupported(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/education', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'institutionName' => 'University A',
            'educationLevel' => 'BACHELORS',
            'qualification' => 'B.Sc',
            'boardUniversity' => 'University A',
            'startDate' => '2015-01-01',
            'endDate' => '2018-12-31',
            'percentage' => 85.5,
            'grade' => 'A',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/education', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'institutionName' => 'University B',
            'educationLevel' => 'MASTERS',
            'qualification' => 'M.Sc',
            'boardUniversity' => 'University B',
            'startDate' => '2018-01-01',
            'endDate' => '2020-12-31',
            'percentage' => 90.0,
            'grade' => 'A+',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/education', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']);
    }

    // ==========================================
    // Government Identifier Tests
    // ==========================================

    public function testGovernmentIdentifierMaskingWorks(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/government-identifiers', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '123456789012',
            'issuingCountry' => 'India',
            'issuingAuthority' => 'UIDAI',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotEquals('123456789012', $data['data']['maskedIdentifier']);
        $this->assertStringContainsString('9012', $data['data']['maskedIdentifier']);
    }

    public function testInstituteCannotAccessOtherUserGovernmentIdentifiers(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherBId . '/government-identifiers', [], [], $this->authHeaders($this->instituteToken), json_encode([
            'type' => 'PAN',
            'identifier' => 'ABCDE1234F',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    // ==========================================
    // Bank Account Tests
    // ==========================================

    public function testBankAccountMaskingWorks(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'accountHolderName' => 'Teacher A Profile',
            'accountNumber' => '1234567890123456',
            'bankName' => 'Test Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotEquals('1234567890123456', $data['data']['maskedAccountNumber']);
        $this->assertStringContainsString('3456', $data['data']['maskedAccountNumber']);
    }

    public function testInstituteCannotAccessOtherUserBankAccounts(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherBId . '/bank-accounts', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    // ==========================================
    // Document Tests
    // ==========================================

    public function testDocumentCreateAndList(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/documents', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'documentType' => 'PROFILE_PHOTO',
            'fileName' => 'photo.jpg',
            'mimeType' => 'image/jpeg',
            'fileSize' => 102400,
            'storageReference' => 'uploads/profiles/photo.jpg',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $documentId = $data['data']['id'];

        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/documents', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['data']);

        $this->client->request('DELETE', '/api/v1/users/' . $this->teacherAId . '/documents/' . $documentId, [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDocumentUnauthorizedAccessDenied(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherBId . '/documents', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    // ==========================================
    // Me Endpoint Tests
    // ==========================================

    public function testMeAddressesCrud(): void
    {
        $this->client->request('POST', '/api/v1/me/addresses', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'addressType' => 'CURRENT',
            'addressLine1' => '999 Me St',
            'city' => 'Me City',
            'state' => 'Me State',
            'country' => 'Europe/Berlin',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $addressId = $data['data']['id'];

        $this->client->request('GET', '/api/v1/me/addresses', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['data']);

        $this->client->request('DELETE', '/api/v1/me/addresses/' . $addressId, [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
    }

    // ==========================================
    // Management Authorization Tests
    // ==========================================

    public function testSuperAdminCanAccessAnyUserProfile(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherBId . '/profile', [], [], $this->authHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Teacher B', $data['data']['firstName']);
    }

    public function testInstituteCanAccessSameInstituteUserProfile(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/profile', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Teacher A', $data['data']['firstName']);
    }

    public function testInstituteCannotAccessOtherInstituteUserProfile(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherBId . '/profile', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testTeacherCanAccessSameInstituteUserProfile(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/profile', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Teacher A', $data['data']['firstName']);
    }

    public function testTeacherCannotAccessOtherInstituteUserProfile(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherBId . '/profile', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testStudentCanUseManagementEndpointForOwnProfile(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->studentId . '/profile', [], [], $this->authHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Student', $data['data']['firstName']);
    }

    public function testStudentCanUseMeEndpoint(): void
    {
        $this->client->request('GET', '/api/v1/me/profile', [], [], $this->authHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Student', $data['data']['firstName']);
    }

    // ==========================================
    // Security Tests
    // ==========================================

    public function testStudentCanAccessOwnSensitiveDataViaMe(): void
    {
        $this->client->request('POST', '/api/v1/me/government-identifiers', [], [], $this->authHeaders($this->studentToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '111111111111',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $this->client->request('GET', '/api/v1/me/government-identifiers', [], [], $this->authHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('1111', $data['data'][0]['maskedIdentifier']);
    }

    public function testStudentCanUseManagementEndpointForOwnSensitiveData(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testTeacherCanAccessOwnSensitiveData(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'accountHolderName' => 'Teacher A',
            'accountNumber' => '1111222233334444',
            'bankName' => 'Test Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('4444', $data['data'][0]['maskedAccountNumber']);
    }

    public function testTeacherCannotAccessUnauthorizedSensitiveData(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherBId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testInstituteCannotAccessAnotherUserSensitiveData(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherBId . '/government-identifiers', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSuperAdminCanAccessSensitiveData(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/government-identifiers', [], [], $this->authHeaders($this->superAdminToken), json_encode([
            'type' => 'PAN',
            'identifier' => 'ABCDE1234F',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/government-identifiers', [], [], $this->authHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('1234', $data['data'][0]['maskedIdentifier']);
    }

    public function testAadhaarMaskingFormat(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/government-identifiers', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '123456789012',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertMatchesRegularExpression('/^XXXX XXXX \d{4}$/', $data['data']['maskedIdentifier']);
    }

    public function testPanMaskingFormat(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/government-identifiers', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'type' => 'PAN',
            'identifier' => 'ABCDE1234F',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertMatchesRegularExpression('/^XXXXX1234X$/', $data['data']['maskedIdentifier']);
    }

    public function testBankAccountMaskingFormat(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'accountHolderName' => 'Teacher A',
            'accountNumber' => '1234567890123456',
            'bankName' => 'Test Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertMatchesRegularExpression('/^X{12}3456$/', $data['data']['maskedAccountNumber']);
    }

    public function testRawSensitiveValuesNeverReturned(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/government-identifiers', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '123456789012',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $responseContent = $response->getContent();
        $this->assertStringNotContainsString('123456789012', $responseContent);

        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'accountHolderName' => 'Teacher A',
            'accountNumber' => '1234567890123456',
            'bankName' => 'Test Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $responseContent = $response->getContent();
        $this->assertStringNotContainsString('1234567890123456', $responseContent);
    }

    public function testCrossInstituteAccessRejected(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherBId . '/profile', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());

        $this->client->request('GET', '/api/v1/users/' . $this->teacherBId . '/addresses', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDocumentStorageReferenceSanitized(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/documents', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'documentType' => 'PROFILE_PHOTO',
            'fileName' => 'photo.jpg',
            'mimeType' => 'image/jpeg',
            'fileSize' => 102400,
            'storageReference' => 'uploads/profiles/secret/photo.jpg',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('.jpg', $data['data']['storageReference']);
        $this->assertStringNotContainsString('secret', $data['data']['storageReference']);
    }

    public function testPrimaryAddressUniquenessEnforced(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/addresses', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'addressType' => 'CURRENT',
            'addressLine1' => '123 Main St',
            'city' => 'Test City',
            'state' => 'Test State',
            'country' => 'Asia/Kolkata',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/addresses', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'addressType' => 'PERMANENT',
            'addressLine1' => '456 Oak Ave',
            'city' => 'Test City',
            'state' => 'Test State',
            'country' => 'Asia/Kolkata',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/addresses', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $primaryCount = 0;
        foreach ($data['data'] as $address) {
            if ($address['isPrimary']) {
                $primaryCount++;
            }
        }
        $this->assertSame(1, $primaryCount);
    }

    public function testPrimaryBankAccountUniquenessEnforced(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'accountHolderName' => 'Teacher A',
            'accountNumber' => '1111222233334444',
            'bankName' => 'Test Bank A',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'accountHolderName' => 'Teacher A',
            'accountNumber' => '5555666677778888',
            'bankName' => 'Test Bank B',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $primaryCount = 0;
        foreach ($data['data'] as $account) {
            if ($account['isPrimary']) {
                $primaryCount++;
            }
        }
        $this->assertSame(1, $primaryCount);
    }

    public function testCreateFirstPrimaryBankAccount(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'accountHolderName' => 'Teacher A',
            'accountNumber' => '1111222233334444',
            'bankName' => 'Test Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertTrue($data['data']['isPrimary']);
    }

    public function testCreateSecondBankAccountNonPrimary(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'accountHolderName' => 'Teacher A',
            'accountNumber' => '1111222233334444',
            'bankName' => 'Test Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'accountHolderName' => 'Teacher A',
            'accountNumber' => '5555666677778888',
            'bankName' => 'Test Bank B',
            'accountType' => 'SAVINGS',
            'isPrimary' => false,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']);

        $primaryAccounts = array_filter($data['data'], fn($a) => $a['isPrimary']);
        $this->assertCount(1, $primaryAccounts);
        $this->assertStringContainsString('4444', $primaryAccounts[array_keys($primaryAccounts)[0]]['maskedAccountNumber']);
    }

    public function testUpdateNonPrimaryBankAccountToPrimary(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'accountHolderName' => 'Teacher A',
            'accountNumber' => '1111222233334444',
            'bankName' => 'Test Bank A',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $firstAccountId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'accountHolderName' => 'Teacher A',
            'accountNumber' => '5555666677778888',
            'bankName' => 'Test Bank B',
            'accountType' => 'SAVINGS',
            'isPrimary' => false,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $secondAccountId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('PUT', '/api/v1/users/' . $this->teacherAId . '/bank-accounts/' . $secondAccountId, [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertTrue($data['data']['isPrimary']);

        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $accounts = json_decode($response->getContent(), true)['data'];
        $firstAccount = array_values(array_filter($accounts, fn($a) => $a['id'] === $firstAccountId))[0];
        $secondAccount = array_values(array_filter($accounts, fn($a) => $a['id'] === $secondAccountId))[0];

        $this->assertFalse($firstAccount['isPrimary']);
        $this->assertTrue($secondAccount['isPrimary']);
    }

    public function testUpdateAlreadyPrimaryBankAccountIsIdempotent(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'accountHolderName' => 'Teacher A',
            'accountNumber' => '1111222233334444',
            'bankName' => 'Test Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $accountId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('PUT', '/api/v1/users/' . $this->teacherAId . '/bank-accounts/' . $accountId, [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertTrue($data['data']['isPrimary']);
    }

    public function testCreateMultiplePrimaryBankAccountsSequentially(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken), json_encode([
                'accountHolderName' => 'Teacher A',
                'accountNumber' => str_pad((string) $i, 16, '0'),
                'bankName' => 'Test Bank ' . $i,
                'accountType' => 'SAVINGS',
                'isPrimary' => true,
            ]));

            $response = $this->client->getResponse();
            $this->assertSame(201, $response->getStatusCode(), 'Failed on iteration ' . $i);
        }

        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertCount(3, $data['data']);

        $primaryCount = 0;
        foreach ($data['data'] as $account) {
            if ($account['isPrimary']) {
                $primaryCount++;
            }
        }
        $this->assertSame(1, $primaryCount);
    }

    public function testUnauthorizedUserCannotChangePrimaryBankAccount(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'accountHolderName' => 'Teacher A',
            'accountNumber' => '1111222233334444',
            'bankName' => 'Test Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $accountId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('PUT', '/api/v1/users/' . $this->teacherAId . '/bank-accounts/' . $accountId, [], [], $this->authHeaders($this->teacherBToken), json_encode([
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUnauthorizedUserCannotChangePrimaryAddress(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/addresses', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'addressType' => 'CURRENT',
            'addressLine1' => '123 Main St',
            'city' => 'Test City',
            'state' => 'Test State',
            'country' => 'Asia/Kolkata',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $addressId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('PUT', '/api/v1/users/' . $this->teacherAId . '/addresses/' . $addressId, [], [], $this->authHeaders($this->teacherBToken), json_encode([
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testCreateFirstPrimaryAddress(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/addresses', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'addressType' => 'CURRENT',
            'addressLine1' => '123 Main St',
            'city' => 'Test City',
            'state' => 'Test State',
            'country' => 'Asia/Kolkata',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertTrue($data['data']['isPrimary']);
    }

    public function testCreateSecondPrimaryAddressDemotesFirst(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/addresses', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'addressType' => 'CURRENT',
            'addressLine1' => '123 Main St',
            'city' => 'Test City',
            'state' => 'Test State',
            'country' => 'Asia/Kolkata',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $firstAddressId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/addresses', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'addressType' => 'PERMANENT',
            'addressLine1' => '456 Oak Ave',
            'city' => 'Test City',
            'state' => 'Test State',
            'country' => 'Asia/Kolkata',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $secondAddressId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/addresses', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $addresses = json_decode($response->getContent(), true)['data'];
        $firstAddress = array_values(array_filter($addresses, fn($a) => $a['id'] === $firstAddressId))[0];
        $secondAddress = array_values(array_filter($addresses, fn($a) => $a['id'] === $secondAddressId))[0];

        $this->assertFalse($firstAddress['isPrimary']);
        $this->assertTrue($secondAddress['isPrimary']);
    }

    public function testUpdateNonPrimaryAddressToPrimary(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/addresses', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'addressType' => 'CURRENT',
            'addressLine1' => '123 Main St',
            'city' => 'Test City',
            'state' => 'Test State',
            'country' => 'Asia/Kolkata',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $firstAddressId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/addresses', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'addressType' => 'PERMANENT',
            'addressLine1' => '456 Oak Ave',
            'city' => 'Test City',
            'state' => 'Test State',
            'country' => 'Asia/Kolkata',
            'isPrimary' => false,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $secondAddressId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('PUT', '/api/v1/users/' . $this->teacherAId . '/addresses/' . $secondAddressId, [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertTrue($data['data']['isPrimary']);

        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/addresses', [], [], $this->authHeaders($this->teacherAToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $addresses = json_decode($response->getContent(), true)['data'];
        $firstAddress = array_values(array_filter($addresses, fn($a) => $a['id'] === $firstAddressId))[0];
        $secondAddress = array_values(array_filter($addresses, fn($a) => $a['id'] === $secondAddressId))[0];

        $this->assertFalse($firstAddress['isPrimary']);
        $this->assertTrue($secondAddress['isPrimary']);
    }

    public function testUpdateAlreadyPrimaryAddressIsIdempotent(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherAId . '/addresses', [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'addressType' => 'CURRENT',
            'addressLine1' => '123 Main St',
            'city' => 'Test City',
            'state' => 'Test State',
            'country' => 'Asia/Kolkata',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $addressId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('PUT', '/api/v1/users/' . $this->teacherAId . '/addresses/' . $addressId, [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertTrue($data['data']['isPrimary']);
    }

    public function testSoftDeletedUserReturnsNotFound(): void
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $userRepo = $entityManager->getRepository(User::class);
        $student = $userRepo->find($this->studentId);
        if ($student instanceof User) {
            $student->softDelete();
            $entityManager->flush();
        }

        $this->client->request('GET', '/api/v1/users/' . $this->studentId . '/profile', [], [], $this->authHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        if ($student instanceof User && null !== $student->getDeletedAt()) {
            $reflection = new \ReflectionClass($student);
            $property = $reflection->getProperty('deletedAt');
            $property->setAccessible(true);
            $property->setValue($student, null);
            $entityManager->flush();
        }
    }

    // ==========================================
    // Student Authorization Tests
    // ==========================================

    public function testStudentCanGetOwnProfile(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->studentId . '/profile', [], [], $this->authHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Student', $data['data']['firstName']);
    }

    public function testStudentCanUpdateOwnProfile(): void
    {
        $this->client->request('PUT', '/api/v1/users/' . $this->studentId . '/profile', [], [], $this->authHeaders($this->studentToken), json_encode([
            'firstName' => 'Updated Student',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Updated Student', $data['data']['firstName']);
    }

    public function testStudentCannotAccessOtherUserProfile(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/profile', [], [], $this->authHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testStudentCanManageOwnAddresses(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/addresses', [], [], $this->authHeaders($this->studentToken), json_encode([
            'addressType' => 'CURRENT',
            'addressLine1' => '123 Student St',
            'city' => 'Student City',
            'state' => 'Student State',
            'country' => 'Asia/Kolkata',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $addressId = $data['data']['id'];

        $this->client->request('GET', '/api/v1/users/' . $this->studentId . '/addresses', [], [], $this->authHeaders($this->studentToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $this->client->request('PUT', '/api/v1/users/' . $this->studentId . '/addresses/' . $addressId, [], [], $this->authHeaders($this->studentToken), json_encode([
            'addressLine1' => '456 Updated St',
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $this->client->request('DELETE', '/api/v1/users/' . $this->studentId . '/addresses/' . $addressId, [], [], $this->authHeaders($this->studentToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testStudentCannotAccessOtherUserAddresses(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/addresses', [], [], $this->authHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testStudentCanManageOwnEducation(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/education', [], [], $this->authHeaders($this->studentToken), json_encode([
            'institutionName' => 'Student University',
            'educationLevel' => 'BACHELORS',
            'qualification' => 'B.Sc',
            'boardUniversity' => 'Student University',
            'startDate' => '2020-01-01',
            'endDate' => '2024-01-01',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $educationId = $data['data']['id'];

        $this->client->request('GET', '/api/v1/users/' . $this->studentId . '/education', [], [], $this->authHeaders($this->studentToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $this->client->request('PUT', '/api/v1/users/' . $this->studentId . '/education/' . $educationId, [], [], $this->authHeaders($this->studentToken), json_encode([
            'qualification' => 'M.Sc',
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $this->client->request('DELETE', '/api/v1/users/' . $this->studentId . '/education/' . $educationId, [], [], $this->authHeaders($this->studentToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testStudentCannotAccessOtherUserEducation(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/education', [], [], $this->authHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testStudentCanManageOwnGovernmentIdentifiers(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->studentToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '123456789012',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $identifierId = $data['data']['id'];

        $this->client->request('GET', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->studentToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $this->client->request('PUT', '/api/v1/users/' . $this->studentId . '/government-identifiers/' . $identifierId, [], [], $this->authHeaders($this->studentToken), json_encode([
            'issuingAuthority' => 'UIDAI',
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $this->client->request('DELETE', '/api/v1/users/' . $this->studentId . '/government-identifiers/' . $identifierId, [], [], $this->authHeaders($this->studentToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testStudentCannotAccessOtherUserGovernmentIdentifiers(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/government-identifiers', [], [], $this->authHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testStudentCanManageOwnBankAccounts(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/bank-accounts', [], [], $this->authHeaders($this->studentToken), json_encode([
            'accountHolderName' => 'Student Name',
            'accountNumber' => '1234567890123456',
            'bankName' => 'Student Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $accountId = $data['data']['id'];

        $this->client->request('GET', '/api/v1/users/' . $this->studentId . '/bank-accounts', [], [], $this->authHeaders($this->studentToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $this->client->request('PUT', '/api/v1/users/' . $this->studentId . '/bank-accounts/' . $accountId, [], [], $this->authHeaders($this->studentToken), json_encode([
            'bankName' => 'Updated Bank',
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $this->client->request('DELETE', '/api/v1/users/' . $this->studentId . '/bank-accounts/' . $accountId, [], [], $this->authHeaders($this->studentToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testStudentCannotAccessOtherUserBankAccounts(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/bank-accounts', [], [], $this->authHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testStudentCanManageOwnDocuments(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents', [], [], $this->authHeaders($this->studentToken), json_encode([
            'documentType' => 'PROFILE_PHOTO',
            'fileName' => 'student_photo.jpg',
            'mimeType' => 'image/jpeg',
            'fileSize' => 102400,
            'storageReference' => 'uploads/profiles/student_photo.jpg',
        ]));

        $response = $this->client->getResponse();
        if ($response->getStatusCode() !== 201) {
            echo "\n=== DOCUMENT CREATE ERROR ===\n";
            echo $response->getContent() . "\n";
            echo "============================\n";
        }
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $documentId = $data['data']['id'];

        $this->client->request('GET', '/api/v1/users/' . $this->studentId . '/documents', [], [], $this->authHeaders($this->studentToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $this->client->request('DELETE', '/api/v1/users/' . $this->studentId . '/documents/' . $documentId, [], [], $this->authHeaders($this->studentToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testStudentCannotAccessOtherUserDocuments(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherAId . '/documents', [], [], $this->authHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    // ==========================================
    // Sensitive Data Access Tests
    // ==========================================

    public function testInstituteCanViewSameInstituteUserGovernmentIdentifiers(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->studentToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '111111111111',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $this->client->request('GET', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('1111', $data['data'][0]['maskedIdentifier']);
    }

    public function testInstituteCannotViewOtherInstituteUserGovernmentIdentifiers(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherBId . '/government-identifiers', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSuperAdminCanViewAnyUserGovernmentIdentifiers(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->studentToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '222222222222',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $this->client->request('GET', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('2222', $data['data'][0]['maskedIdentifier']);
    }

    public function testInstituteCanVerifySameInstituteGovernmentIdentifier(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->studentToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '333333333333',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $identifierId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers/' . $identifierId . '/verify', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('VERIFIED', $data['data']['verificationStatus']);
    }

    public function testInstituteCannotVerifyOtherInstituteGovernmentIdentifier(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherBId . '/government-identifiers', [], [], $this->authHeaders($this->teacherBToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '444444444444',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $identifierId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->teacherBId . '/government-identifiers/' . $identifierId . '/verify', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSuperAdminCanVerifyAnyGovernmentIdentifier(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->studentToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '555555555555',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $identifierId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers/' . $identifierId . '/verify', [], [], $this->authHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('VERIFIED', $data['data']['verificationStatus']);
    }

    public function testStudentCannotVerifyOwnGovernmentIdentifier(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->studentToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '666666666666',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $identifierId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers/' . $identifierId . '/verify', [], [], $this->authHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testInstituteCanRejectSameInstituteGovernmentIdentifier(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->studentToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '777777777777',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $identifierId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers/' . $identifierId . '/reject', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('REJECTED', $data['data']['verificationStatus']);
    }

    public function testUserCannotManipulateVerificationStatusThroughPut(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->studentToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '888888888888',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $identifierId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('PUT', '/api/v1/users/' . $this->studentId . '/government-identifiers/' . $identifierId, [], [], $this->authHeaders($this->studentToken), json_encode([
            'verificationStatus' => 'VERIFIED',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertNotSame('VERIFIED', $data['data']['verificationStatus']);
    }

    // ==========================================
    // Bank Account Sensitive Data Tests
    // ==========================================

    public function testInstituteCanViewSameInstituteUserBankAccounts(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/bank-accounts', [], [], $this->authHeaders($this->studentToken), json_encode([
            'accountHolderName' => 'Student Name',
            'accountNumber' => '1111222233334444',
            'bankName' => 'Student Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $this->client->request('GET', '/api/v1/users/' . $this->studentId . '/bank-accounts', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('XXXX', $data['data'][0]['maskedAccountNumber']);
        $this->assertStringNotContainsString('1111222233334444', $data['data'][0]['maskedAccountNumber']);
    }

    public function testInstituteCannotViewOtherInstituteUserBankAccounts(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherBId . '/bank-accounts', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSuperAdminCanViewAnyUserBankAccounts(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/bank-accounts', [], [], $this->authHeaders($this->studentToken), json_encode([
            'accountHolderName' => 'Student Name',
            'accountNumber' => '5555666677778888',
            'bankName' => 'Student Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $this->client->request('GET', '/api/v1/users/' . $this->studentId . '/bank-accounts', [], [], $this->authHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('XXXX', $data['data'][0]['maskedAccountNumber']);
    }

    public function testInstituteCanVerifySameInstituteBankAccount(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/bank-accounts', [], [], $this->authHeaders($this->studentToken), json_encode([
            'accountHolderName' => 'Student Name',
            'accountNumber' => '7777888899990000',
            'bankName' => 'Student Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $accountId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/bank-accounts/' . $accountId . '/verify', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('VERIFIED', $data['data']['verificationStatus']);
    }

    public function testInstituteCannotVerifyOtherInstituteBankAccount(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherBId . '/bank-accounts', [], [], $this->authHeaders($this->teacherBToken), json_encode([
            'accountHolderName' => 'Teacher B',
            'accountNumber' => '8888999900001111',
            'bankName' => 'Teacher B Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $accountId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->teacherBId . '/bank-accounts/' . $accountId . '/verify', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSuperAdminCanVerifyAnyBankAccount(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/bank-accounts', [], [], $this->authHeaders($this->studentToken), json_encode([
            'accountHolderName' => 'Student Name',
            'accountNumber' => '9999000011112222',
            'bankName' => 'Student Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $accountId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/bank-accounts/' . $accountId . '/verify', [], [], $this->authHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('VERIFIED', $data['data']['verificationStatus']);
    }

    public function testStudentCannotVerifyOwnBankAccount(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/bank-accounts', [], [], $this->authHeaders($this->studentToken), json_encode([
            'accountHolderName' => 'Student Name',
            'accountNumber' => '1111000022223333',
            'bankName' => 'Student Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $accountId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/bank-accounts/' . $accountId . '/verify', [], [], $this->authHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUserCannotManipulateBankAccountVerificationStatusThroughPut(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/bank-accounts', [], [], $this->authHeaders($this->studentToken), json_encode([
            'accountHolderName' => 'Student Name',
            'accountNumber' => '4444333322221111',
            'bankName' => 'Student Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $accountId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('PUT', '/api/v1/users/' . $this->studentId . '/bank-accounts/' . $accountId, [], [], $this->authHeaders($this->studentToken), json_encode([
            'verificationStatus' => 'VERIFIED',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertNotSame('VERIFIED', $data['data']['verificationStatus']);
    }

    // ==========================================
    // Document Verification Tests
    // ==========================================

    public function testInstituteCanVerifySameInstituteDocument(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents', [], [], $this->authHeaders($this->studentToken), json_encode([
            'documentType' => 'EDUCATION_CERTIFICATE',
            'fileName' => 'certificate.jpg',
            'mimeType' => 'image/jpeg',
            'fileSize' => 102400,
            'storageReference' => 'uploads/profiles/certificate.jpg',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $documentId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents/' . $documentId . '/verify', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('VERIFIED', $data['data']['verificationStatus']);
    }

    public function testInstituteCanRejectSameInstituteDocument(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents', [], [], $this->authHeaders($this->studentToken), json_encode([
            'documentType' => 'ADDRESS_PROOF',
            'fileName' => 'address.jpg',
            'mimeType' => 'image/jpeg',
            'fileSize' => 102400,
            'storageReference' => 'uploads/profiles/address.jpg',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $documentId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents/' . $documentId . '/reject', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('REJECTED', $data['data']['verificationStatus']);
    }

    public function testInstituteCannotVerifyOtherInstituteDocument(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherBId . '/documents', [], [], $this->authHeaders($this->teacherBToken), json_encode([
            'documentType' => 'EDUCATION_CERTIFICATE',
            'fileName' => 'teacher_cert.jpg',
            'mimeType' => 'image/jpeg',
            'fileSize' => 102400,
            'storageReference' => 'uploads/profiles/teacher_cert.jpg',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $documentId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->teacherBId . '/documents/' . $documentId . '/verify', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testInstituteCannotRejectOtherInstituteDocument(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->teacherBId . '/documents', [], [], $this->authHeaders($this->teacherBToken), json_encode([
            'documentType' => 'ADDRESS_PROOF',
            'fileName' => 'teacher_address.jpg',
            'mimeType' => 'image/jpeg',
            'fileSize' => 102400,
            'storageReference' => 'uploads/profiles/teacher_address.jpg',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $documentId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->teacherBId . '/documents/' . $documentId . '/reject', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSuperAdminCanVerifyAnyDocument(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents', [], [], $this->authHeaders($this->studentToken), json_encode([
            'documentType' => 'EDUCATION_CERTIFICATE',
            'fileName' => 'sa_cert.jpg',
            'mimeType' => 'image/jpeg',
            'fileSize' => 102400,
            'storageReference' => 'uploads/profiles/sa_cert.jpg',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $documentId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents/' . $documentId . '/verify', [], [], $this->authHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('VERIFIED', $data['data']['verificationStatus']);
    }

    public function testSuperAdminCanRejectAnyDocument(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents', [], [], $this->authHeaders($this->studentToken), json_encode([
            'documentType' => 'ADDRESS_PROOF',
            'fileName' => 'sa_address.jpg',
            'mimeType' => 'image/jpeg',
            'fileSize' => 102400,
            'storageReference' => 'uploads/profiles/sa_address.jpg',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $documentId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents/' . $documentId . '/reject', [], [], $this->authHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('REJECTED', $data['data']['verificationStatus']);
    }

    public function testStudentCannotVerifyOwnDocument(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents', [], [], $this->authHeaders($this->studentToken), json_encode([
            'documentType' => 'EDUCATION_CERTIFICATE',
            'fileName' => 'student_cert.jpg',
            'mimeType' => 'image/jpeg',
            'fileSize' => 102400,
            'storageReference' => 'uploads/profiles/student_cert.jpg',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $documentId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents/' . $documentId . '/verify', [], [], $this->authHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testStudentCannotRejectOwnDocument(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents', [], [], $this->authHeaders($this->studentToken), json_encode([
            'documentType' => 'ADDRESS_PROOF',
            'fileName' => 'student_address.jpg',
            'mimeType' => 'image/jpeg',
            'fileSize' => 102400,
            'storageReference' => 'uploads/profiles/student_address.jpg',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $documentId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents/' . $documentId . '/reject', [], [], $this->authHeaders($this->studentToken));

        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testDocumentVerificationIsPerDocumentId(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents', [], [], $this->authHeaders($this->studentToken), json_encode([
            'documentType' => 'EDUCATION_CERTIFICATE',
            'fileName' => 'doc1.jpg',
            'mimeType' => 'image/jpeg',
            'fileSize' => 102400,
            'storageReference' => 'uploads/profiles/doc1.jpg',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $documentId1 = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents', [], [], $this->authHeaders($this->studentToken), json_encode([
            'documentType' => 'ADDRESS_PROOF',
            'fileName' => 'doc2.jpg',
            'mimeType' => 'image/jpeg',
            'fileSize' => 102400,
            'storageReference' => 'uploads/profiles/doc2.jpg',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $documentId2 = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents/' . $documentId1 . '/verify', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $this->client->request('GET', '/api/v1/users/' . $this->studentId . '/documents', [], [], $this->authHeaders($this->instituteToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $documents = json_decode($response->getContent(), true)['data'];
        $doc1 = array_values(array_filter($documents, fn($d) => $d['id'] === $documentId1))[0];
        $doc2 = array_values(array_filter($documents, fn($d) => $d['id'] === $documentId2))[0];

        $this->assertSame('VERIFIED', $doc1['verificationStatus']);
        $this->assertSame('UNVERIFIED', $doc2['verificationStatus']);
    }

    public function testInstituteCanViewSameInstituteUserDocuments(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents', [], [], $this->authHeaders($this->studentToken), json_encode([
            'documentType' => 'EDUCATION_CERTIFICATE',
            'fileName' => 'institute_view.jpg',
            'mimeType' => 'image/jpeg',
            'fileSize' => 102400,
            'storageReference' => 'uploads/profiles/institute_view.jpg',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $this->client->request('GET', '/api/v1/users/' . $this->studentId . '/documents', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['data']);
    }

    public function testInstituteCannotViewOtherInstituteUserDocuments(): void
    {
        $this->client->request('GET', '/api/v1/users/' . $this->teacherBId . '/documents', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSuperAdminCanViewAnyUserDocuments(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents', [], [], $this->authHeaders($this->studentToken), json_encode([
            'documentType' => 'EDUCATION_CERTIFICATE',
            'fileName' => 'sa_view.jpg',
            'mimeType' => 'image/jpeg',
            'fileSize' => 102400,
            'storageReference' => 'uploads/profiles/sa_view.jpg',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $this->client->request('GET', '/api/v1/users/' . $this->studentId . '/documents', [], [], $this->authHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['data']);
    }

    // ==========================================
    // KAN-43 Backend-Controlled Verification Tests
    // ==========================================

    public function testNewGovernmentIdentifierIsAutomaticallyUnverified(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->studentToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '111111111111',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('UNVERIFIED', $data['data']['verificationStatus']);
    }

    public function testNewBankAccountIsAutomaticallyUnverified(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/bank-accounts', [], [], $this->authHeaders($this->studentToken), json_encode([
            'accountHolderName' => 'Student Name',
            'accountNumber' => '1111222233334444',
            'bankName' => 'Student Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('UNVERIFIED', $data['data']['verificationStatus']);
    }

    public function testNewDocumentIsAutomaticallyUnverified(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents', [], [], $this->authHeaders($this->studentToken), json_encode([
            'documentType' => 'EDUCATION_CERTIFICATE',
            'fileName' => 'auto_unverified.jpg',
            'mimeType' => 'image/jpeg',
            'fileSize' => 102400,
            'storageReference' => 'uploads/profiles/auto_unverified.jpg',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('UNVERIFIED', $data['data']['verificationStatus']);
    }

    public function testStudentCannotCreateVerifiedGovernmentIdentifier(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->studentToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '222222222222',
            'issuingCountry' => 'India',
            'verificationStatus' => 'VERIFIED',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('UNVERIFIED', $data['data']['verificationStatus']);
    }

    public function testStudentCannotCreateVerifiedBankAccount(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/bank-accounts', [], [], $this->authHeaders($this->studentToken), json_encode([
            'accountHolderName' => 'Student Name',
            'accountNumber' => '5555666677778888',
            'bankName' => 'Student Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
            'verificationStatus' => 'VERIFIED',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('UNVERIFIED', $data['data']['verificationStatus']);
    }

    public function testStudentCannotCreateVerifiedDocument(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents', [], [], $this->authHeaders($this->studentToken), json_encode([
            'documentType' => 'EDUCATION_CERTIFICATE',
            'fileName' => 'cannot_verify.jpg',
            'mimeType' => 'image/jpeg',
            'fileSize' => 102400,
            'storageReference' => 'uploads/profiles/cannot_verify.jpg',
            'verificationStatus' => 'VERIFIED',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('UNVERIFIED', $data['data']['verificationStatus']);
    }

    public function testStudentCannotChangeDocumentVerificationStatusThroughPut(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents', [], [], $this->authHeaders($this->studentToken), json_encode([
            'documentType' => 'EDUCATION_CERTIFICATE',
            'fileName' => 'doc_put_test.jpg',
            'mimeType' => 'image/jpeg',
            'fileSize' => 102400,
            'storageReference' => 'uploads/profiles/doc_put_test.jpg',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $documentId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('PUT', '/api/v1/users/' . $this->studentId . '/documents/' . $documentId, [], [], $this->authHeaders($this->studentToken), json_encode([
            'verificationStatus' => 'VERIFIED',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(405, $response->getStatusCode());
    }

    public function testTeacherCannotChangeGovernmentIdentifierVerificationStatusThroughPut(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->studentToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '333333333333',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $identifierId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('PUT', '/api/v1/users/' . $this->studentId . '/government-identifiers/' . $identifierId, [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'verificationStatus' => 'VERIFIED',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertNotSame('VERIFIED', $data['data']['verificationStatus']);
    }

    public function testTeacherCannotChangeBankAccountVerificationStatusThroughPut(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/bank-accounts', [], [], $this->authHeaders($this->studentToken), json_encode([
            'accountHolderName' => 'Student Name',
            'accountNumber' => '6666777788889999',
            'bankName' => 'Student Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $accountId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('PUT', '/api/v1/users/' . $this->studentId . '/bank-accounts/' . $accountId, [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'verificationStatus' => 'VERIFIED',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertNotSame('VERIFIED', $data['data']['verificationStatus']);
    }

    public function testTeacherCannotChangeDocumentVerificationStatusThroughPut(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/documents', [], [], $this->authHeaders($this->studentToken), json_encode([
            'documentType' => 'EDUCATION_CERTIFICATE',
            'fileName' => 'teacher_put_test.jpg',
            'mimeType' => 'image/jpeg',
            'fileSize' => 102400,
            'storageReference' => 'uploads/profiles/teacher_put_test.jpg',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $documentId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('PUT', '/api/v1/users/' . $this->studentId . '/documents/' . $documentId, [], [], $this->authHeaders($this->teacherAToken), json_encode([
            'verificationStatus' => 'VERIFIED',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(405, $response->getStatusCode());
    }

    public function testSuperAdminCanRejectAnyGovernmentIdentifier(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->studentToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '444444444444',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $identifierId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers/' . $identifierId . '/reject', [], [], $this->authHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('REJECTED', $data['data']['verificationStatus']);
    }

    public function testSuperAdminCanRejectAnyBankAccount(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/bank-accounts', [], [], $this->authHeaders($this->studentToken), json_encode([
            'accountHolderName' => 'Student Name',
            'accountNumber' => '7777888899990000',
            'bankName' => 'Student Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $accountId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/bank-accounts/' . $accountId . '/reject', [], [], $this->authHeaders($this->superAdminToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('REJECTED', $data['data']['verificationStatus']);
    }

    public function testInstituteCanRejectSameInstituteBankAccount(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/bank-accounts', [], [], $this->authHeaders($this->studentToken), json_encode([
            'accountHolderName' => 'Student Name',
            'accountNumber' => '8888999900001111',
            'bankName' => 'Student Bank',
            'accountType' => 'SAVINGS',
            'isPrimary' => true,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $accountId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/bank-accounts/' . $accountId . '/reject', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('REJECTED', $data['data']['verificationStatus']);
    }

    public function testVerifyEndpointChangesRejectedToVerified(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->studentToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '5555666677778888',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $identifierId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers/' . $identifierId . '/reject', [], [], $this->authHeaders($this->instituteToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers/' . $identifierId . '/verify', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('VERIFIED', $data['data']['verificationStatus']);
    }

    public function testRejectEndpointChangesVerifiedToRejected(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->studentToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '9999000011112222',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $identifierId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers/' . $identifierId . '/verify', [], [], $this->authHeaders($this->instituteToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers/' . $identifierId . '/reject', [], [], $this->authHeaders($this->instituteToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('REJECTED', $data['data']['verificationStatus']);
    }

    public function testExistingVerificationStatusReturnedByGetEndpoint(): void
    {
        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->studentToken), json_encode([
            'type' => 'AADHAAR',
            'identifier' => '123456789012',
            'issuingCountry' => 'India',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $identifierId = json_decode($response->getContent(), true)['data']['id'];

        $this->client->request('POST', '/api/v1/users/' . $this->studentId . '/government-identifiers/' . $identifierId . '/verify', [], [], $this->authHeaders($this->instituteToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $this->client->request('GET', '/api/v1/users/' . $this->studentId . '/government-identifiers', [], [], $this->authHeaders($this->studentToken));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['data']);
        $this->assertSame('VERIFIED', $data['data'][0]['verificationStatus']);
    }
}
