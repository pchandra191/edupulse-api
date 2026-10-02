<?php

declare(strict_types=1);

namespace App\Tests\Controller\Analysis;

use App\Entity\User;
use App\Enum\Analysis\AnalysisDocumentConstraints;
use App\Enum\AnalysisSource;
use App\Enum\AnalysisStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\MongoDB\AnalysisRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

class AnalysisUploadControllerTest extends WebTestCase
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

    public function testStudentCanUploadQuestionPaper(): void
    {
        // Create analysis first
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $analysisId = $data['data']['analysisId'];

        // Upload question paper
        $file = $this->createTestFile('test_question_paper.pdf', 'application/pdf', 'test pdf content');
        
        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'QUESTION_PAPER',
        ], [
            'file' => $file,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('File uploaded successfully.', $data['message']);
        $this->assertSame($analysisId, $data['data']['analysisId']);
        $this->assertSame('QUESTION_PAPER', $data['data']['documentType']);
        $this->assertArrayHasKey('fileId', $data['data']);
        $this->assertSame('UPLOADED', $data['data']['status']);

        // Verify MongoDB was updated
        $container = $this->client->getContainer();
        $mongoRepo = $container->get(AnalysisRepository::class);
        $mongoAnalysis = $mongoRepo->findByAnalysisIdAndStudentId($analysisId, (string) $this->student1Id);
        $this->assertNotNull($mongoAnalysis);
        $this->assertSame($data['data']['fileId'], $mongoAnalysis->getDocuments()->getQuestionPaper()->getFileId());
        $this->assertSame('UPLOADED', $mongoAnalysis->getDocuments()->getQuestionPaper()->getStatus());
    }

    public function testStudentCanUploadAnswerSheet(): void
    {
        // Create analysis first
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $analysisId = $data['data']['analysisId'];

        // Upload answer sheet
        $file = $this->createTestFile('test_answer_sheet.jpg', 'image/jpeg', 'test image content');
        
        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'file' => $file,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('ANSWER_SHEET', $data['data']['documentType']);
        $this->assertSame('UPLOADED', $data['data']['status']);

        // Verify MongoDB was updated
        $container = $this->client->getContainer();
        $mongoRepo = $container->get(AnalysisRepository::class);
        $mongoAnalysis = $mongoRepo->findByAnalysisIdAndStudentId($analysisId, (string) $this->student1Id);
        $this->assertNotNull($mongoAnalysis);
        $this->assertSame($data['data']['fileId'], $mongoAnalysis->getDocuments()->getAnswerSheet()->getFileId());
        $this->assertSame('UPLOADED', $mongoAnalysis->getDocuments()->getAnswerSheet()->getStatus());
    }

    public function testStudentCannotUploadToAnotherStudentsAnalysis(): void
    {
        // Create analysis for student1
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $analysisId = $data['data']['analysisId'];

        // Try to upload to student1's analysis as student2
        $file = $this->createTestFile('test.pdf', 'application/pdf', 'test content');
        
        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'QUESTION_PAPER',
        ], [
            'file' => $file,
        ], $this->authHeaders($this->student2Token));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testInvalidAnalysisIdIsRejected(): void
    {
        $file = $this->createTestFile('test.pdf', 'application/pdf', 'test content');
        
        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => 'invalid-analysis-id',
            'documentType' => 'QUESTION_PAPER',
        ], [
            'file' => $file,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testMissingFileIsRejected(): void
    {
        // Create analysis first
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $analysisId = $data['data']['analysisId'];

        // Upload without file
        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'QUESTION_PAPER',
        ], [], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('file', $data['errors'] ?? []);
    }

    public function testInvalidDocumentTypeIsRejected(): void
    {
        // Create analysis first
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $analysisId = $data['data']['analysisId'];

        // Upload with invalid document type
        $file = $this->createTestFile('test.pdf', 'application/pdf', 'test content');
        
        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'INVALID_TYPE',
        ], [
            'file' => $file,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('documentType', $data['errors'] ?? []);
    }

    public function testUnsupportedFileTypeIsRejected(): void
    {
        // Create analysis first
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $analysisId = $data['data']['analysisId'];

        // Upload unsupported file type (text/plain)
        $file = $this->createTestFile('test.txt', 'text/plain', 'test content');
        
        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'QUESTION_PAPER',
        ], [
            'file' => $file,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('file', $data['errors'] ?? []);
    }

    public function testFileAtMaxSizeIsAccepted(): void
    {
        $analysisId = $this->createAnalysisAndGetId();

        $tempFile = sys_get_temp_dir() . '/at_max.pdf';
        $pdfHeader = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> /Contents 4 0 R >>\nendobj\n4 0 obj\n<< /Length 44 >>\nstream\nBT /F1 12 Tf 100 700 Td (Test PDF) Tj ET\nendstream\nendobj\nxref\n0 5\n0000000000 65535 f \n0000000010 00000 n \n0000000060 00000 n \n0000000117 00000 n \n0000000216 00000 n \ntrailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n293\n%%EOF";
        $paddingPattern = "% padding\n";
        $fh = fopen($tempFile, 'wb');
        fwrite($fh, $pdfHeader);
        $remaining = AnalysisDocumentConstraints::MAX_FILE_SIZE - strlen($pdfHeader);
        while ($remaining > 0) {
            $chunk = str_repeat($paddingPattern, (int) (min($remaining, 8192) / strlen($paddingPattern) + 1));
            $written = fwrite($fh, substr($chunk, 0, $remaining));
            $remaining -= $written;
        }
        fclose($fh);

        $file = new UploadedFile(
            $tempFile,
            'at_max.pdf',
            'application/pdf',
            null,
            true
        );

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'QUESTION_PAPER',
        ], [
            'file' => $file,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        @unlink($tempFile);
    }

    public function testFileJustAboveMaxSizeIsRejected(): void
    {
        $analysisId = $this->createAnalysisAndGetId();

        $tempFile = sys_get_temp_dir() . '/over_max.pdf';
        $content = str_repeat('x', AnalysisDocumentConstraints::MAX_FILE_SIZE + 1);
        file_put_contents($tempFile, $content);

        $file = new UploadedFile(
            $tempFile,
            'over_max.pdf',
            'application/pdf',
            null,
            true
        );

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'QUESTION_PAPER',
        ], [
            'file' => $file,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('file', $data['errors'] ?? []);
        $this->assertStringContainsString('too large', strtolower($data['errors']['file'][0]));

        @unlink($tempFile);
    }

    public function testSmallFileAccepted(): void
    {
        $analysisId = $this->createAnalysisAndGetId();

        $file = $this->createTestFile('small.pdf', 'application/pdf', 'small content');

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'file' => $file,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testOversizedFileIsRejected(): void
    {
        $analysisId = $this->createAnalysisAndGetId();

        // Create a file larger than the maximum allowed size
        $tempFile = sys_get_temp_dir() . '/large.pdf';
        $largeContent = str_repeat('x', AnalysisDocumentConstraints::MAX_FILE_SIZE + 1024);
        file_put_contents($tempFile, $largeContent);

        $file = new UploadedFile(
            $tempFile,
            'large.pdf',
            'application/pdf',
            null,
            true
        );

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'QUESTION_PAPER',
        ], [
            'file' => $file,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('file', $data['errors'] ?? []);

        @unlink($tempFile);
    }

    private function createAnalysisAndGetId(): string
    {
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);

        return $data['data']['analysisId'];
    }

    public function testMongoDBDocumentPreservesUnrelatedFields(): void
    {
        // Create analysis first
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $analysisId = $data['data']['analysisId'];

        // Upload question paper
        $file = $this->createTestFile('test.pdf', 'application/pdf', 'test content');
        
        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'QUESTION_PAPER',
        ], [
            'file' => $file,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        // Verify other fields are preserved
        $container = $this->client->getContainer();
        $mongoRepo = $container->get(AnalysisRepository::class);
        $mongoAnalysis = $mongoRepo->findByAnalysisIdAndStudentId($analysisId, (string) $this->student1Id);
        $this->assertNotNull($mongoAnalysis);
        
        // Check source, status, exam, processing fields are preserved
        $this->assertSame('EXTERNAL_UPLOAD', $mongoAnalysis->getSource());
        $this->assertSame('DRAFT', $mongoAnalysis->getStatus());
        $this->assertNotNull($mongoAnalysis->getExam());
        $this->assertNotNull($mongoAnalysis->getProcessing());
        
        // Answer sheet should still be NOT_UPLOADED
        $this->assertNull($mongoAnalysis->getDocuments()->getAnswerSheet()->getFileId());
        $this->assertSame('NOT_UPLOADED', $mongoAnalysis->getDocuments()->getAnswerSheet()->getStatus());
    }

    public function testExistingEndpointsStillWork(): void
    {
        // Create analysis
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
        ]));
        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $analysisId = $data['data']['analysisId'];

        // Upload question paper
        $file = $this->createTestFile('test.pdf', 'application/pdf', 'test content');
        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'QUESTION_PAPER',
        ], [
            'file' => $file,
        ], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        // Test GET /api/v1/student/analyses still works
        $this->client->request('GET', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['data']['items']);

        // Test GET /api/v1/student/analyses/{analysisId} still works
        $this->client->request('GET', '/api/v1/student/analyses/' . $analysisId, [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame($analysisId, $data['data']['analysisId']);
        $this->assertSame('UPLOADED', $data['data']['documents']['questionPaper']['status']);
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
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];
    }

    private function createTestFile(string $filename, string $mimeType, string $content): UploadedFile
    {
        $tempFile = sys_get_temp_dir() . '/' . $filename;
        
        // Use proper file content for mime type detection
        if ($mimeType === 'application/pdf') {
            $content = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> /Contents 4 0 R >>\nendobj\n4 0 obj\n<< /Length 44 >>\nstream\nBT /F1 12 Tf 100 700 Td (Test PDF) Tj ET\nendstream\nendobj\nxref\n0 5\n0000000000 65535 f \n0000000010 00000 n \n0000000060 00000 n \n0000000117 00000 n \n0000000216 00000 n \ntrailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n293\n%%EOF";
        } elseif ($mimeType === 'image/jpeg') {
            // Minimal JPEG header
            $content = hex2bin('FFD8FFE000104A46494600010101004800480000FFDB004300080606070605080707070909080A0C140D0C0B0B0C1912130F141D1A1F1E1D1A1C1C20242E2720222C232C2C2C313A3A3A3A3A3A3A3A3A3A3A');
        } elseif ($mimeType === 'image/png') {
            // Minimal PNG header
            $content = hex2bin('89504E470D0A1A0A0000000D4948445200000001000000010802000000907753DE0000000C4944415408D763F8FFFF030000060005E100160000000049454E44AE426082');
        }
        
        file_put_contents($tempFile, $content);
        
        return new UploadedFile(
            $tempFile,
            $filename,
            $mimeType,
            null,
            true
        );
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