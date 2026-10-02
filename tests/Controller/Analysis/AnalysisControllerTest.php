<?php

declare(strict_types=1);

namespace App\Tests\Controller\Analysis;

use App\Entity\Institute;
use App\Entity\User;
use App\Enum\InstituteStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\MongoDB\AnalysisRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class AnalysisControllerTest extends WebTestCase
{
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private ?Uuid $student1Id = null;
    private ?Uuid $student2Id = null;
    private ?string $student1Token = null;
    private ?string $student2Token = null;
    private array $createdEmails = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $unique = uniqid();

        $student1 = new User();
        $this->student1Id = Uuid::v7();
        $reflection = new \ReflectionClass($student1);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($student1, $this->student1Id);
        $student1Email = 'student1-' . $unique . '@edupulse.ai';
        $student1->setEmail($student1Email);
        $student1->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student1->setRoles([UserRole::Student->value]);
        $student1->setStatus(UserStatus::Active);
        $student1->verify();
        $student1->setFirstName('Student');
        $student1->setLastName('One');

        $student2 = new User();
        $this->student2Id = Uuid::v7();
        $reflection2 = new \ReflectionClass($student2);
        $property2 = $reflection2->getProperty('id');
        $property2->setAccessible(true);
        $property2->setValue($student2, $this->student2Id);
        $student2Email = 'student2-' . $unique . '@edupulse.ai';
        $student2->setEmail($student2Email);
        $student2->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student2->setRoles([UserRole::Student->value]);
        $student2->setStatus(UserStatus::Active);
        $student2->verify();
        $student2->setFirstName('Student');
        $student2->setLastName('Two');

        $entityManager->persist($student1);
        $entityManager->persist($student2);
        $entityManager->flush();

        $this->createdEmails = [$student1Email, $student2Email];

        $this->student1Token = $this->login($student1Email, 'Student123!');
        $this->student2Token = $this->login($student2Email, 'Student123!');

        // Clear MongoDB analyses for clean test state
        $mongoAnalysisRepo = $container->get(AnalysisRepository::class);
        foreach ($mongoAnalysisRepo->findByStudentId((string) $this->student1Id) as $analysis) {
            $mongoAnalysisRepo->remove($analysis);
        }
        foreach ($mongoAnalysisRepo->findByStudentId((string) $this->student2Id) as $analysis) {
            $mongoAnalysisRepo->remove($analysis);
        }
    }

    public function testAuthenticatedStudentCanCreateAnalysis(): void
    {
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

$data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Analysis created successfully.', $data['message']);
        $this->assertArrayHasKey('analysisId', $data['data']);
        $this->assertSame('EXTERNAL_UPLOAD', $data['data']['source']);
        $this->assertSame('DRAFT', $data['data']['status']);
        $this->assertArrayHasKey('createdAt', $data['data']);
    }

    public function testCreatedAnalysisIsLinkedToCorrectStudent(): void
    {
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $analysisId = $data['data']['analysisId'];

        // Verify in PostgreSQL
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();
        $pgAnalysis = $entityManager->getRepository(\App\Entity\Analysis::class)->findByAnalysisIdAndStudent($analysisId, $entityManager->getReference(User::class, $this->student1Id));
        $this->assertNotNull($pgAnalysis);
        $this->assertSame($analysisId, $pgAnalysis->getAnalysisId());
        $this->assertSame('EXTERNAL_UPLOAD', $pgAnalysis->getSource());
        $this->assertSame('DRAFT', $pgAnalysis->getStatus());

        // Verify in MongoDB
        $mongoAnalysisRepo = $container->get(AnalysisRepository::class);
        $mongoAnalysis = $mongoAnalysisRepo->findByAnalysisIdAndStudentId($analysisId, (string) $this->student1Id);
        $this->assertNotNull($mongoAnalysis);
        $this->assertSame($analysisId, $mongoAnalysis->getAnalysisId());
        $this->assertSame((string) $this->student1Id, $mongoAnalysis->getStudentId());
        $this->assertSame('EXTERNAL_UPLOAD', $mongoAnalysis->getSource());
        $this->assertSame('DRAFT', $mongoAnalysis->getStatus());
    }

    public function testStudentCanListOnlyTheirOwnAnalyses(): void
    {
        // Create analysis for student1
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
        ]));
        $response1 = $this->client->getResponse();
        $this->assertSame(201, $response1->getStatusCode(), $response1->getContent());

        // Create another analysis for student1
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
        ]));
        $response2 = $this->client->getResponse();
        $this->assertSame(201, $response2->getStatusCode(), $response2->getContent());

        // Create analysis for student2
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student2Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
        ]));
        $response3 = $this->client->getResponse();
        $this->assertSame(201, $response3->getStatusCode(), $response3->getContent());

        // List analyses for student1 - should only see 2
        $this->client->request('GET', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']['items']);
        $this->assertSame(2, $data['data']['pagination']['totalItems']);

        // List analyses for student2 - should only see 1
        $this->client->request('GET', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student2Token));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['data']['items']);
        $this->assertSame(1, $data['data']['pagination']['totalItems']);
    }

    public function testStudentCanRetrieveTheirOwnAnalysis(): void
    {
        // Create analysis for student1 using the service directly
        $container = static::getContainer();
        $service = $container->get(\App\Service\Analysis\AnalysisService::class);
        $user = $container->get('doctrine')->getManager()->getReference(User::class, $this->student1Id);
        
        $request = new \App\DTO\Analysis\CreateAnalysisRequestDTO(source: 'EXTERNAL_UPLOAD');
        $result = $service->createAnalysis($user, $request);
        $analysisId = $result->analysisId;
        
        // Debug: Check MongoDB directly
        $repo = $container->get(\App\Repository\MongoDB\AnalysisRepository::class);
        $mongoAnalysis = $repo->findByAnalysisId($analysisId);
        $this->assertNotNull($mongoAnalysis, 'Analysis should exist in MongoDB after service call');
        
        // Debug: Check what user ID the controller sees
        $this->client->request('GET', '/api/v1/auth/me', [], [], $this->authHeaders($this->student1Token));
        $meResponse = $this->client->getResponse();
        $meData = json_decode($meResponse->getContent(), true);
        file_put_contents('C:/temp/test_debug.log', "test student1Id: " . (string) $this->student1Id . "\n", FILE_APPEND);
        file_put_contents('C:/temp/test_debug.log', "me user ID: " . $meData['data']['id'] . "\n", FILE_APPEND);
        file_put_contents('C:/temp/test_debug.log', "match: " . ((string) $this->student1Id === $meData['data']['id'] ? 'yes' : 'no') . "\n", FILE_APPEND);

        // Retrieve the analysis via API
        $this->client->request('GET', '/api/v1/student/analyses/' . $analysisId, [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame($analysisId, $data['data']['analysisId']);
        $this->assertSame('EXTERNAL_UPLOAD', $data['data']['source']);
        $this->assertSame('DRAFT', $data['data']['status']);
        $this->assertArrayHasKey('documents', $data['data']);
        $this->assertArrayHasKey('exam', $data['data']);
        $this->assertArrayHasKey('processing', $data['data']);
        $this->assertArrayHasKey('createdAt', $data['data']);
        $this->assertArrayHasKey('updatedAt', $data['data']);
    }

    public function testStudentCannotRetrieveAnotherStudentsAnalysis(): void
    {
        // Create analysis for student1
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $analysisId = $data['data']['analysisId'];

        // Try to retrieve the analysis as student2
        $this->client->request('GET', '/api/v1/student/analyses/' . $analysisId, [], [], $this->authHeaders($this->student2Token));
        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testUnauthorizedUsersCannotAccessStudentAnalysisEndpoints(): void
    {
        // Test without token
        $this->client->request('POST', '/api/v1/student/analyses', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([]));
        $response = $this->client->getResponse();
        $this->assertSame(401, $response->getStatusCode(), $response->getContent());

        $this->client->request('GET', '/api/v1/student/analyses', [], [], ['CONTENT_TYPE' => 'application/json']);
        $response = $this->client->getResponse();
        $this->assertSame(401, $response->getStatusCode(), $response->getContent());

        $this->client->request('GET', '/api/v1/student/analyses/some-id', [], [], ['CONTENT_TYPE' => 'application/json']);
        $response = $this->client->getResponse();
        $this->assertSame(401, $response->getStatusCode(), $response->getContent());
    }

    public function testInvalidAnalysisIdIsHandledCorrectly(): void
    {
        $this->client->request('GET', '/api/v1/student/analyses/invalid-id', [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testNonStudentRoleCannotAccessEndpoints(): void
    {
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        // Create a teacher user
        $teacher = new User();
        $teacherId = Uuid::v7();
        $reflection = new \ReflectionClass($teacher);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($teacher, $teacherId);
        $teacherEmail = 'teacher-' . uniqid() . '@edupulse.ai';
        $teacher->setEmail($teacherEmail);
        $teacher->setPassword(password_hash('Teacher123!', PASSWORD_BCRYPT));
        $teacher->setRoles([UserRole::Teacher->value]);
        $teacher->setStatus(UserStatus::Active);
        $teacher->verify();
        $teacher->setFirstName('Teacher');
        $teacher->setLastName('Test');

        $institute = new Institute();
        $instituteId = Uuid::v7();
        $reflection2 = new \ReflectionClass($institute);
        $property2 = $reflection2->getProperty('id');
        $property2->setAccessible(true);
        $property2->setValue($institute, $instituteId);
        $institute->setName('Test Institute Teacher');
        $institute->setCode('TIT' . uniqid());
        $institute->setEmail('test-institute-teacher-' . uniqid() . '@edupulse.ai');
        $institute->setStatus(InstituteStatus::Active);

        $teacher->setInstitute($institute);

        $entityManager->persist($institute);
        $entityManager->persist($teacher);
        $entityManager->flush();

        $this->createdEmails[] = $teacherEmail;

        $teacherToken = $this->login($teacherEmail, 'Teacher123!');

        // Try to access student analysis endpoints as teacher
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($teacherToken), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode(), $response->getContent());

        $this->client->request('GET', '/api/v1/student/analyses', [], [], $this->authHeaders($teacherToken));
        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode(), $response->getContent());

        $this->client->request('GET', '/api/v1/student/analyses/some-id', [], [], $this->authHeaders($teacherToken));
        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode(), $response->getContent());
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

    protected function tearDown(): void
    {
        if ($this->client === null) {
            parent::tearDown();
            return;
        }

        try {
            $container = $this->client->getContainer();
            $entityManager = $container->get('doctrine')->getManager();
            $userRepo = $entityManager->getRepository(User::class);

            foreach ($userRepo->findBy(['email' => $this->createdEmails]) as $user) {
                $entityManager->remove($user);
            }

            $entityManager->flush();

            // Clean up MongoDB
            $mongoAnalysisRepo = $container->get(AnalysisRepository::class);
            foreach ($mongoAnalysisRepo->findByStudentId((string) $this->student1Id) as $analysis) {
                $mongoAnalysisRepo->remove($analysis);
            }
            foreach ($mongoAnalysisRepo->findByStudentId((string) $this->student2Id) as $analysis) {
                $mongoAnalysisRepo->remove($analysis);
            }
        } catch (\Throwable) {
        }

        $this->client = null;
        $this->student1Token = null;
        $this->student2Token = null;
        $this->createdEmails = [];

        parent::tearDown();
    }
}