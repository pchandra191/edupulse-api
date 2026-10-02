<?php

declare(strict_types=1);

namespace App\Tests\Controller\Analysis;

use App\Entity\User;
use App\Entity\Analysis;
use App\Entity\ExamSet;
use App\Enum\AnalysisSource;
use App\Enum\AnalysisStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\AnalysisRepository;
use App\Repository\ExamSetRepository;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use App\Service\Analysis\AnalysisProcessingService;
use App\Service\Analysis\AnalysisWorkflowService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

class AnalysisEndToEndTest extends WebTestCase
{
    private ?KernelBrowser $client = null;
    private ?string $student1Token = null;
    private ?Uuid $student1Id = null;
    private ?string $examSetId = null;
    private ?string $analysisId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $entityManager = $container->get('doctrine')->getManager();

        // Create test student
        $student1 = new User();
        $this->student1Id = Uuid::v7();
        $reflection = new \ReflectionClass($student1);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($student1, $this->student1Id);
        $student1->setEmail('student-e2e-' . uniqid() . '@edupulse.ai');
        $student1->setPassword(password_hash('Student123!', PASSWORD_BCRYPT));
        $student1->setRoles([UserRole::Student->value]);
        $student1->setStatus(UserStatus::Active);
        $student1->verify();
        $student1->setFirstName('Test');
        $student1->setLastName('Student');

        $entityManager->persist($student1);
        $entityManager->flush();

        // Login as student
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => $student1->getEmail(), 'password' => 'Student123!']));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->student1Token = $data['data']['accessToken'];

        // Create an ExamSet
        $examSet = new ExamSet();
        $examSet->setStudent($student1);
        $examSet->setAcademicYear('2026');
        $examSet->setAcademicClass('8');
        $examSet->setSection('A');
        $examSet->setExamName('Unit Test');
        $examSet->setExamSubType('Mid Term');

        $entityManager->persist($examSet);
        $entityManager->flush();

        $this->examSetId = $examSet->getId()->toRfc4122();
    }

    public function testCompleteAnalysisFlow(): void
    {
        // PHASE 2: CREATE ANALYSIS
        $this->client->request('POST', '/api/v1/student/analyses', [], [], $this->authHeaders($this->student1Token), json_encode([
            'source' => 'EXTERNAL_UPLOAD',
            'examSetId' => $this->examSetId,
        ]));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('analysisId', $data['data']);
        $this->assertSame('DRAFT', $data['data']['status']);
        $this->assertSame($this->examSetId, $data['data']['examSetId']);

        $this->analysisId = $data['data']['analysisId'];

// PHASE 3: UPLOAD REAL ANSWER SHEET
        // Use a real PDF fixture (PDF) since the existing tests use PDFs for answer sheets
        $file = $this->createTestAnswerSheet();
        $this->assertFileExists($file->getPathname());

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $this->analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'file' => $file,
        ], $this->authHeaders($this->student1Token, 'multipart/form-data'));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('ANSWER_SHEET', $data['data']['documentType']);
        $this->assertSame('UPLOADED', $data['data']['status']);
        $this->assertArrayHasKey('fileId', $data['data']);

        // PHASE 4 & 5: RUN MESSENGER WORKER
        // Clear any existing messages first
        $this->clearMessengerQueue();

        // Process the queued message
        $this->runMessengerWorker();

        // PHASE 6 & 7: VERIFY DOCUMENT EXTRACTION
        // Wait a moment for processing to complete
        sleep(2);

        // PHASE 8: VERIFY MONGODB PERSISTENCE
        $this->client->request('GET', '/api/v1/student/analyses/' . $this->analysisId, [], [], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        
        $exam = $data['data']['exam'] ?? [];
        
        // PHASE 9: VERIFY FRONTEND-CONSUMABLE DATA
        $this->assertArrayHasKey('extractedAnswers', $exam);
        $this->assertArrayHasKey('extractedMetadata', $exam);
        $this->assertArrayHasKey('extractionResult', $exam);
        $this->assertArrayHasKey('extractionConfidence', $exam);
        $this->assertArrayHasKey('extractedAt', $exam);
        $this->assertArrayHasKey('extractionVersion', $exam);
        
        // Verify answers structure (may be empty if OCR required)
        $extractedAnswers = $exam['extractedAnswers'];
        $this->assertIsArray($extractedAnswers);
        
        // Verify metadata structure
        $extractedMetadata = $exam['extractedMetadata'];
        $this->assertIsArray($extractedMetadata);

        // PHASE 10: METADATA CONFIRMATION - Save metadata
        $metadata = [
            'totalMarks' => 100,
            'scoredMarks' => 75,
            'subjectName' => 'Mathematics',
        ];

        $this->client->request('PUT', '/api/v1/student/analyses/' . $this->analysisId . '/metadata', [], [], $this->authHeaders($this->student1Token), json_encode($metadata));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        // PHASE 11: VERIFY PUBLISH
        // Should auto-publish after metadata is confirmed
        $this->client->request('GET', '/api/v1/student/analyses/' . $this->analysisId, [], [], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        // After metadata confirmation, should be CONFIRMED (was NEEDS_REVIEW, now complete)
        $this->assertSame('CONFIRMED', $data['data']['status']);

        // PHASE 12: START AI ANALYSIS
        $this->client->request('POST', '/api/v1/student/analyses/' . $this->analysisId . '/analyze', [], [], $this->authHeaders($this->student1Token), json_encode([]));

        $response = $this->client->getResponse();
        $this->assertSame(202, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('PROCESSING', $data['data']['status']);

        // Run AI worker
        $this->runAiMessengerWorker();

        // Wait for AI processing
        sleep(3);

        // PHASE 13: VERIFY AI PROCESSING
        $this->client->request('GET', '/api/v1/student/analyses/' . $this->analysisId, [], [], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('DONE', $data['data']['status']);

        // Verify AI analysis exists
        $this->assertArrayHasKey('aiAnalysis', $data['data']);
        $aiAnalysis = $data['data']['aiAnalysis'];
        $this->assertIsArray($aiAnalysis);
        $this->assertArrayHasKey('questions', $aiAnalysis);
        $this->assertArrayHasKey('summary', $aiAnalysis);
        $this->assertArrayHasKey('patterns', $aiAnalysis);
        $this->assertArrayHasKey('recommendations', $aiAnalysis);

        // PHASE 14: FINAL API VERIFICATION
        $this->client->request('GET', '/api/v1/student/analyses/' . $this->analysisId, [], [], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('DONE', $data['data']['status']);

        // Verify complete data
        $this->assertArrayHasKey('documents', $data['data']);
        $this->assertArrayHasKey('answerSheet', $data['data']['documents']);
        $this->assertArrayHasKey('fileId', $data['data']['documents']['answerSheet']);

        $this->assertArrayHasKey('exam', $data['data']);
        $this->assertArrayHasKey('extractedAnswers', $data['data']['exam']);
        $this->assertArrayHasKey('extractedMetadata', $data['data']['exam']);
        $this->assertArrayHasKey('processing', $data['data']);
        $this->assertArrayHasKey('aiAnalysis', $data['data']);

        // Verify examSet relationship
        $this->assertArrayHasKey('examSet', $data['data']);
        $examSet = $data['data']['examSet'];
        $this->assertSame($this->examSetId, $examSet['id']);
    }

    private function getTestPdfPath(): string
    {
        // Create a test PDF using the same approach as other tests
        return $this->createTestPdf();
    }

private function createTestAnswerSheet(): \Symfony\Component\HttpFoundation\File\UploadedFile
    {
        // Use the existing fixture PDF which has valid extractable text
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        $fixturePath = $projectDir . '/tests/fixtures/analysis/answer-sheet.pdf';
        $this->assertFileExists($fixturePath, 'Fixture PDF not found at ' . $fixturePath);
        
        return new \Symfony\Component\HttpFoundation\File\UploadedFile(
            $fixturePath,
            'answer-sheet.pdf',
            'application/pdf',
            null,
            true
        );
    }

    private function createTestFile(string $filename, string $mimeType, string $content): \Symfony\Component\HttpFoundation\File\UploadedFile
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

    private function clearMessengerQueue(): void
    {
        $container = $this->client->getContainer();
        $connection = $container->get('doctrine.dbal.default_connection');
        $connection->executeStatement('TRUNCATE TABLE messenger_messages');
    }

    private function runMessengerWorker(): void
    {
        // Run the messenger worker to process messages (document extraction)
        $container = $this->client->getContainer();
        $handler = $container->get(\App\Handler\Analysis\ProcessAnalysisMessageHandler::class);
        
        // Create and process the message
        $message = \App\Message\Analysis\ProcessAnalysisMessage::create(
            $this->analysisId,
            (string) $this->student1Id
        );
        
        $handler->__invoke($message);
    }

    private function runAiMessengerWorker(): void
    {
        // Run the AI analysis message handler
        $container = $this->client->getContainer();
        $handler = $container->get(\App\MessageHandler\ProcessAnalysisAIMessageHandler::class);
        
        $message = new \App\Message\ProcessAnalysisAIMessage(
            $this->analysisId,
            (string) $this->student1Id
        );
        
        $handler->__invoke($message);
    }

    private function authHeaders(string $token, string $contentType = 'application/json'): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => $contentType,
        ];
    }
}