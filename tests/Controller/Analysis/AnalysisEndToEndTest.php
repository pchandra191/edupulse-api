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
$this->assertArrayHasKey('files', $data['data']);
        $this->assertArrayHasKey('fileId', $data['data']['files'][0]);

        // PHASE 4: RUN MESSENGER WORKER (document preparation only - no AI extraction)
        // Clear any existing messages first
        $this->clearMessengerQueue();

        // Process the queued message (document preparation only - no AI extraction)
        $this->runMessengerWorker();

        // Wait a moment for processing to complete
        sleep(2);

        // PHASE 5: VERIFY DOCUMENT PREPARATION (no AI extraction yet)
        $this->client->request('GET', '/api/v1/student/analyses/' . $this->analysisId, [], [], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        
        // Document preparation should set status to NEEDS_REVIEW
        $this->assertSame('NEEDS_REVIEW', $data['data']['status']);
        
        $exam = $data['data']['exam'] ?? [];
        
        // Document preparation should not have extracted answers/metadata yet
        $this->assertArrayHasKey('extractedAnswers', $exam);
        $this->assertArrayHasKey('extractedMetadata', $exam);
        // Answers and metadata should be empty/pending since no AI extraction has run yet
        $extractedAnswers = $exam['extractedAnswers'];
        $this->assertIsArray($extractedAnswers);
        $extractedMetadata = $exam['extractedMetadata'];
        $this->assertIsArray($extractedMetadata);
        // AI analysis should not exist yet (should be null)
        $this->assertNull($data['data']['aiAnalysis'] ?? null);
        
        // PHASE 6: METADATA CONFIRMATION - Save metadata
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

        // PHASE 7: VERIFY CONFIRMED STATUS
        // Should auto-transition to CONFIRMED after metadata is confirmed
        $this->client->request('GET', '/api/v1/student/analyses/' . $this->analysisId, [], [], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        // Metadata confirmation is the single trigger for AI analysis: the
        // analysis moves straight from NEEDS_REVIEW into PROCESSING and one
        // analyze message is dispatched. It must not rest at CONFIRMED.
        $this->assertSame('PROCESSING', $data['data']['status']);
        $this->assertSame('PROCESSING', $data['data']['processing']['status']);

        // PHASE 8: START AI ANALYSIS (auto-dispatched by backend after confirmation)
        // Note: In the new architecture, the backend automatically dispatches the AI analysis
        // after metadata confirmation. We'll run the AI worker manually to simulate the async processing.
        
        // Run AI worker - in test environment, this will fail due to fake OpenAI API key
        // We catch the exception to verify the workflow up to the Direct AI call
        try {
            $this->runAiMessengerWorker();
        } catch (\App\Exception\VisionApiException $e) {
            // Expected in test environment with fake API key
            $this->assertStringContainsString('401', $e->getMessage());
        } catch (\Throwable $e) {
            // Re-throw unexpected exceptions
            throw $e;
        }

        // Wait for AI processing
        sleep(3);

        // PHASE 8: VERIFY AI PROCESSING
        $this->client->request('GET', '/api/v1/student/analyses/' . $this->analysisId, [], [], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        
        // In test environment, OpenAI API key is fake, so Direct AI will fail with 401
        // The workflow is verified up to the Direct AI call attempt
        // Status will be FAILED due to invalid test API key, but workflow is verified
        $status = $data['data']['status'];
        $this->assertContains($status, ['DONE', 'FAILED'], 'Status should be DONE or FAILED');
        
        // If Direct AI succeeded, verify AI analysis exists
        if ($status === 'DONE') {
            $this->assertArrayHasKey('aiAnalysis', $data['data']);
            $aiAnalysis = $data['data']['aiAnalysis'];
            $this->assertIsArray($aiAnalysis);
            $this->assertArrayHasKey('questions', $aiAnalysis);
            $this->assertArrayHasKey('summary', $aiAnalysis);
            $this->assertArrayHasKey('patterns', $aiAnalysis);
            $this->assertArrayHasKey('recommendations', $aiAnalysis);
        }

        // PHASE 14: FINAL API VERIFICATION
        $this->client->request('GET', '/api/v1/student/analyses/' . $this->analysisId, [], [], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        
        // Status should be DONE (if OpenAI worked) or FAILED (due to test API key)
        $this->assertContains($data['data']['status'], ['DONE', 'FAILED']);

        // Verify complete data structure
        $this->assertArrayHasKey('documents', $data['data']);
        $this->assertArrayHasKey('answerSheet', $data['data']['documents']);
        $this->assertArrayHasKey('fileId', $data['data']['documents']['answerSheet']);

        $this->assertArrayHasKey('exam', $data['data']);
        $this->assertArrayHasKey('extractedAnswers', $data['data']['exam']);
        $this->assertArrayHasKey('extractedMetadata', $data['data']['exam']);
        $this->assertArrayHasKey('processing', $data['data']);
        
        // aiAnalysis may or may not exist depending on Direct AI success
        if (isset($data['data']['aiAnalysis'])) {
            $this->assertIsArray($data['data']['aiAnalysis']);
        }
    }

    private function getTestPdfPath(): string
    {
        // Create a test PDF using the same approach as other tests
        return $this->createTestPdf();
    }

private function createTestAnswerSheet(): \Symfony\Component\HttpFoundation\File\UploadedFile
    {
        // Create a test PDF with extractable text on the fly
        return $this->createTestFile('answer-sheet.pdf', 'application/pdf', '');
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
        $handler = $container->get(\App\Handler\Analysis\ProcessAnalysisMessageHandler::class);
        
        $message = new \App\Message\Analysis\ProcessAnalysisMessage(
            $this->analysisId,
            (string) $this->student1Id,
            'analyze',
            false,
            0
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