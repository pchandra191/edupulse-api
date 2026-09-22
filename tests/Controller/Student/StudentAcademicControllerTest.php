<?php

declare(strict_types=1);

namespace App\Tests\Controller\Student;

use App\Entity\Institute;
use App\Entity\User;
use App\Enum\InstituteStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Entity\UserProfile;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class StudentAcademicControllerTest extends WebTestCase
{
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private ?Uuid $instituteId = null;
    private ?Uuid $independentStudentId = null;
    private ?Uuid $instituteStudentId = null;
    private ?Uuid $otherStudentId = null;
    private ?string $independentStudentToken = null;
    private ?string $instituteStudentToken = null;
    private ?string $otherStudentToken = null;
    private array $createdEmails = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $unique = uniqid();

        $institute = new Institute();
        $this->instituteId = Uuid::v7();
        $reflection = new \ReflectionClass($institute);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($institute, $this->instituteId);
        $institute->setName('Test Institute Academic');
        $institute->setCode('TIA' . $unique);
        $institute->setEmail('test-institute-academic-' . $unique . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);

        $instituteStudent = new User();
        $this->instituteStudentId = Uuid::v7();
        $instReflection = new \ReflectionClass($instituteStudent);
        $instProperty = $instReflection->getProperty('id');
        $instProperty->setAccessible(true);
        $instProperty->setValue($instituteStudent, $this->instituteStudentId);
        $instituteStudentEmail = 'institute-student-' . $unique . '@edupulse.ai';
        $instituteStudent->setEmail($instituteStudentEmail);
        $instituteStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $instituteStudent->setRoles([UserRole::Student->value]);
        $instituteStudent->setStatus(UserStatus::Active);
        $instituteStudent->verify();
        $instituteStudent->setFirstName('Institute');
        $instituteStudent->setLastName('Student');
        $instituteStudent->setInstitute($institute);

        $independentStudent = new User();
        $this->independentStudentId = Uuid::v7();
        $indReflection = new \ReflectionClass($independentStudent);
        $indProperty = $indReflection->getProperty('id');
        $indProperty->setAccessible(true);
        $indProperty->setValue($independentStudent, $this->independentStudentId);
        $independentStudentEmail = 'independent-student-' . $unique . '@edupulse.ai';
        $independentStudent->setEmail($independentStudentEmail);
        $independentStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $independentStudent->setRoles([UserRole::Student->value]);
        $independentStudent->setStatus(UserStatus::Active);
        $independentStudent->verify();
        $independentStudent->setFirstName('Independent');
        $independentStudent->setLastName('Student');

        $otherStudent = new User();
        $this->otherStudentId = Uuid::v7();
        $otherReflection = new \ReflectionClass($otherStudent);
        $otherProperty = $otherReflection->getProperty('id');
        $otherProperty->setAccessible(true);
        $otherProperty->setValue($otherStudent, $this->otherStudentId);
        $otherStudentEmail = 'other-student-' . $unique . '@edupulse.ai';
        $otherStudent->setEmail($otherStudentEmail);
        $otherStudent->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $otherStudent->setRoles([UserRole::Student->value]);
        $otherStudent->setStatus(UserStatus::Active);
        $otherStudent->verify();
        $otherStudent->setFirstName('Other');
        $otherStudent->setLastName('Student');

        $entityManager->persist($institute);
        $entityManager->persist($instituteStudent);
        $entityManager->persist($independentStudent);
        $entityManager->persist($otherStudent);
        $entityManager->flush();

        $this->createdEmails = [
            $instituteStudentEmail,
            $independentStudentEmail,
            $otherStudentEmail,
        ];

        $this->independentStudentToken = $this->login($independentStudentEmail, 'Student123!');
        $this->instituteStudentToken = $this->login($instituteStudentEmail, 'Student123!');
        $this->otherStudentToken = $this->login($otherStudentEmail, 'Student123!');
    }

    public function testIndependentStudentWithNoAcademicInformation(): void
    {
        $this->client->request('GET', '/api/v1/student/academic-details', [], [], $this->authHeaders($this->independentStudentToken));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNull($data['data']['academicYear']);
        $this->assertNull($data['data']['academicClass']);
        $this->assertNull($data['data']['section']);
    }

    public function testIndependentStudentCanSetAllFields(): void
    {
        $this->client->request('PUT', '/api/v1/student/academic-details', [], [], $this->authHeaders($this->independentStudentToken), json_encode([
            'academicYear' => '2026-2027',
            'academicClass' => 'Class 12',
            'section' => 'A',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('2026-2027', $data['data']['academicYear']);
        $this->assertSame('Class 12', $data['data']['academicClass']);
        $this->assertSame('A', $data['data']['section']);

        $this->client->request('GET', '/api/v1/student/academic-details', [], [], $this->authHeaders($this->independentStudentToken));
        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);
        $this->assertSame('2026-2027', $data['data']['academicYear']);
        $this->assertSame('Class 12', $data['data']['academicClass']);
        $this->assertSame('A', $data['data']['section']);

        $this->cleanupProfile($this->independentStudentId);
    }

    public function testIndependentStudentCanUpdateIndividualFields(): void
    {
        $this->client->request('PUT', '/api/v1/student/academic-details', [], [], $this->authHeaders($this->independentStudentToken), json_encode([
            'academicYear' => '2026-2027',
            'academicClass' => null,
            'section' => null,
        ]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('PUT', '/api/v1/student/academic-details', [], [], $this->authHeaders($this->independentStudentToken), json_encode([
            'academicYear' => null,
            'academicClass' => 'B.Sc 1st Year',
            'section' => null,
        ]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('PUT', '/api/v1/student/academic-details', [], [], $this->authHeaders($this->independentStudentToken), json_encode([
            'academicYear' => null,
            'academicClass' => null,
            'section' => 'Computer Science',
        ]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Computer Science', $data['data']['section']);

        $this->cleanupProfile($this->independentStudentId);
    }

    public function testIndependentStudentCanUpdateAllTogether(): void
    {
        $this->client->request('PUT', '/api/v1/student/academic-details', [], [], $this->authHeaders($this->independentStudentToken), json_encode([
            'academicYear' => '2025-26',
            'academicClass' => 'B.Sc 1st Year',
            'section' => 'Computer Science',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('2025-26', $data['data']['academicYear']);
        $this->assertSame('B.Sc 1st Year', $data['data']['academicClass']);
        $this->assertSame('Computer Science', $data['data']['section']);

        $this->cleanupProfile($this->independentStudentId);
    }

    public function testEmptyValuesAreSupported(): void
    {
        $this->client->request('PUT', '/api/v1/student/academic-details', [], [], $this->authHeaders($this->independentStudentToken), json_encode([
            'academicYear' => '',
            'academicClass' => '',
            'section' => '',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertNull($data['data']['academicYear']);
        $this->assertNull($data['data']['academicClass']);
        $this->assertNull($data['data']['section']);

        $this->cleanupProfile($this->independentStudentId);
    }

    public function testOversizedValuesAreRejected(): void
    {
        $this->client->request('PUT', '/api/v1/student/academic-details', [], [], $this->authHeaders($this->independentStudentToken), json_encode([
            'academicYear' => str_repeat('a', 300),
            'academicClass' => 'Valid Class',
            'section' => 'A',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testStudentCannotModifyAnotherStudentsAcademicDetails(): void
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();

        $profileBefore = $entityManager->getRepository(UserProfile::class)->findOneBy([
            'userId' => $this->independentStudentId,
        ]);
        $this->assertNull($profileBefore);

        $this->client->request('PUT', '/api/v1/student/academic-details', [], [], $this->authHeaders($this->independentStudentToken), json_encode([
            'academicYear' => '2026-2027',
            'academicClass' => 'Class 12',
            'section' => 'A',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $independentProfile = $entityManager->getRepository(UserProfile::class)->findOneBy([
            'userId' => $this->independentStudentId,
        ]);
        $this->assertNotNull($independentProfile);
        $this->assertSame('2026-2027', $independentProfile->getAcademicYear());

        $otherProfile = $entityManager->getRepository(UserProfile::class)->findOneBy([
            'userId' => $this->otherStudentId,
        ]);
        $this->assertNull($otherProfile);

        $this->cleanupProfile($this->independentStudentId);
    }

    public function testInstituteStudentCannotUseIndependentAcademicDetails(): void
    {
        $this->client->request('GET', '/api/v1/student/academic-details', [], [], $this->authHeaders($this->instituteStudentToken));
        $response = $this->client->getResponse();
        $this->assertSame(400, $response->getStatusCode(), $response->getContent());

        $this->client->request('PUT', '/api/v1/student/academic-details', [], [], $this->authHeaders($this->instituteStudentToken), json_encode([
            'academicYear' => '2026-2027',
            'academicClass' => 'Class 12',
            'section' => 'A',
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(400, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testIndependentAcademicDetailsDoNotCreateEnrollment(): void
    {
        $this->client->request('PUT', '/api/v1/student/academic-details', [], [], $this->authHeaders($this->independentStudentToken), json_encode([
            'academicYear' => '2026-2027',
            'academicClass' => 'Class 12',
            'section' => 'A',
        ]));

        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $enrollments = $entityManager->getRepository(\App\Entity\StudentEnrollment::class)->findBy([
            'student' => $entityManager->getReference(User::class, $this->independentStudentId),
        ]);

        $this->assertCount(0, $enrollments, 'No enrollment should be created from independent academic details');
    }

    private function login(string $email, string $password): string
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $email, 'password' => $password]));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('accessToken', $data['data'] ?? []);

        return $data['data']['accessToken'];
    }

    private function authHeaders(string $token): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];
    }

    private function cleanupProfile(Uuid $userId): void
    {
        $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $profile = $entityManager->getRepository(UserProfile::class)->findOneBy([
            'userId' => $userId,
        ]);
        if ($profile !== null) {
            $entityManager->remove($profile);
            $entityManager->flush();
        }
    }

    protected function tearDown(): void
    {
        if ($this->client === null) {
            parent::tearDown();
            return;
        }

        try {
            $entityManager = $this->client->getContainer()->get('doctrine')->getManager();
            $userRepo = $entityManager->getRepository(User::class);

            foreach ($userRepo->findBy(['email' => $this->createdEmails]) as $user) {
                $entityManager->remove($user);
            }

            $entityManager->flush();
        } catch (\Throwable) {
        }

        $this->client = null;
        $this->independentStudentToken = null;
        $this->instituteStudentToken = null;
        $this->otherStudentToken = null;
        $this->createdEmails = [];

        parent::tearDown();
    }
}