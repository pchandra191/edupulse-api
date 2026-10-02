<?php

declare(strict_types=1);

namespace App\Tests\Controller\Analysis;

use App\Entity\ExamSet;
use App\Entity\User;
use App\Enum\AnalysisSource;
use App\Enum\AnalysisStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\ExamSetRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class ExamSetAnalysisTest extends WebTestCase
{
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;
    private ?Uuid $student1Id = null;
    private ?string $student1Token = null;

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

        $entityManager->persist($student1);
        $entityManager->flush();

        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $student1Email, 'password' => 'Student123!']));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->student1Token = $data['data']['accessToken'];
    }

    public function testStudentCanCreateAnalysisWithExamSet(): void
    {
        // Create an ExamSet first
        $this->client->request('POST', '/api/v1/student/exam-sets', [], [], $this->authHeaders($this->student1Token), json_encode([
            'academicYear' => '2026–27',
            'academicClass' => '8',
            'section' => 'A',
            'examName' => 'Mid Term',
            'examSubType' => 'Term Examination',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $examSetId = $data['data']['id'];

        // Create analysis with examSetId
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
            'examSetId' => $examSetId,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('analysisId', $data['data']);
        $this->assertSame($examSetId, $data['data']['examSetId']);
        $this->assertSame('DRAFT', $data['data']['status']);

        $analysisId = $data['data']['analysisId'];

        // Verify the analysis is linked to the ExamSet in PostgreSQL
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();
        $analysisRepo = $entityManager->getRepository(\App\Entity\Analysis::class);
        $pgAnalysis = $analysisRepo->findByAnalysisIdAndStudent($analysisId, $entityManager->getReference(User::class, $this->student1Id));
        
        $this->assertNotNull($pgAnalysis);
        $this->assertSame($examSetId, $pgAnalysis->getExamSet()->getId()->toRfc4122());

        // Verify in MongoDB
        $mongoRepo = $container->get(\App\Repository\MongoDB\AnalysisRepository::class);
        $mongoAnalysis = $mongoRepo->findByAnalysisIdAndStudentId($analysisId, (string) $this->student1Id);
        $this->assertNotNull($mongoAnalysis);
        $this->assertSame('DRAFT', $mongoAnalysis->getStatus());
    }

    public function testStudentCannotCreateAnalysisWithAnotherStudentsExamSet(): void
    {
        // Create student 2
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        $student2 = new \App\Entity\User();
        $student2Id = Uuid::v7();
        $reflection = new \ReflectionClass($student2);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($student2, $student2Id);
        $student2->setEmail('student2-' . uniqid() . '@edupulse.ai');
        $student2->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student2->setRoles([UserRole::Student->value]);
        $student2->setStatus(UserStatus::Active);
        $student2->verify();
        $student2->setFirstName('Student');
        $student2->setLastName('Two');

        $entityManager->persist($student2);
        $entityManager->flush();

        // Create ExamSet for student2
        $examSet = new \App\Entity\ExamSet();
        $examSet->setStudent($student2);
        $examSet->setAcademicYear('2026–27');
        $examSet->setAcademicClass('8');
        $examSet->setSection('A');
        $examSet->setExamName('Mid Term');
        $entityManager->persist($examSet);
        $entityManager->flush();
        $examSetId = $examSet->getId()->toRfc4122();

        // Student 1 tries to create analysis with student2's exam set
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
            'examSetId' => $examSetId,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testAnalysisCreationWithoutExamSetStillWorks(): void
    {
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('analysisId', $data['data']);
        $this->assertNull($data['data']['examSetId']);
        $this->assertSame('DRAFT', $data['data']['status']);
    }

    public function testExamSetDetailReturnsItsAnalyses(): void
    {
        // Create an ExamSet
        $this->client->request('POST', '/api/v1/student/exam-sets', [], [], $this->authHeaders($this->student1Token), json_encode([
            'academicYear' => '2026–27',
            'academicClass' => '8',
            'section' => 'A',
            'examName' => 'Mid Term',
            'examSubType' => 'Term Examination',
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $examSetId = $data['data']['id'];

        // Create analysis with examSetId
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
            'examSetId' => $examSetId,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $analysisId = $data['data']['analysisId'];

        // Get ExamSet detail
        $this->client->request('GET', '/api/v1/student/exam-sets/' . $examSetId, [], [], $this->authHeaders($this->student1Token));
        
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame($examSetId, $data['data']['id']);
        $this->assertCount(1, $data['data']['analyses']);
        $this->assertSame($analysisId, $data['data']['analyses'][0]['analysisId']);
        $this->assertSame('DRAFT', $data['data']['analyses'][0]['status']);
    }

    private function authHeaders(string $token): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ];
    }
}