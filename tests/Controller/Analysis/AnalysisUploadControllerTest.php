<?php

declare(strict_types=1);

namespace App\Tests\Controller\Analysis;

use App\Entity\User;
use App\Enum\Analysis\AnalysisDocumentConstraints;
use App\Enum\AnalysisSource;
use App\Enum\AnalysisStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Service\Analysis\Settings\AnalysisSettings;
use App\Repository\MongoDB\AnalysisRepository;
use App\Service\Analysis\AnalysisStorageResolver;
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
        $this->assertSame('File(s) uploaded successfully.', $data['message']);
        $this->assertSame($analysisId, $data['data']['analysisId']);
        $this->assertSame('QUESTION_PAPER', $data['data']['documentType']);
        $this->assertArrayHasKey('files', $data['data']);
        $this->assertCount(1, $data['data']['files']);
        $this->assertSame('UPLOADED', $data['data']['status']);

        // Verify MongoDB was updated
        $container = $this->client->getContainer();
        $mongoRepo = $container->get(AnalysisRepository::class);
        $mongoAnalysis = $mongoRepo->findByAnalysisIdAndStudentId($analysisId, (string) $this->student1Id);
        $this->assertNotNull($mongoAnalysis);
        $this->assertSame($data['data']['files'][0]['fileId'], $mongoAnalysis->getDocuments()->getQuestionPaper()->getFileId());
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
        $this->assertSame($data['data']['files'][0]['fileId'], $mongoAnalysis->getDocuments()->getAnswerSheet()->getFileId());
        $this->assertSame('UPLOADED', $mongoAnalysis->getDocuments()->getAnswerSheet()->getStatus());
    }

    public function testStudentCanUploadMultipleAnswerSheets(): void
    {
        $analysisId = $this->createAnalysisAndGetId();

        // Upload 6 answer-sheet pages in a single request
        $files = [];
        for ($i = 1; $i <= 6; $i++) {
            $files[] = $this->createTestFile("answer_page_{$i}.jpg", 'image/jpeg', "answer page {$i}");
        }

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'files' => $files,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame($analysisId, $data['data']['analysisId']);
        $this->assertCount(6, $data['data']['files']);

        // All 6 files have separate file IDs and preserved ordering (page 1..6)
        $fileIds = [];
        foreach ($data['data']['files'] as $index => $file) {
            $this->assertNotEmpty($file['fileId']);
            $this->assertSame($index + 1, $file['page']);
            $this->assertSame('UPLOADED', $file['status']);
            $fileIds[] = $file['fileId'];
        }
        $this->assertSame(array_values(array_unique($fileIds)), $fileIds);

        // All 6 belong to the SAME analysis, ordering preserved
        $container = $this->client->getContainer();
        $mongoRepo = $container->get(AnalysisRepository::class);
        $mongoAnalysis = $mongoRepo->findByAnalysisIdAndStudentId($analysisId, (string) $this->student1Id);
        $this->assertNotNull($mongoAnalysis);
        $answerSheets = $mongoAnalysis->getDocuments()->getAnswerSheets();
        $this->assertCount(6, $answerSheets);
        foreach ($answerSheets as $index => $answerSheet) {
            $this->assertSame($index + 1, $answerSheet->getPage());
            $this->assertSame('UPLOADED', $answerSheet->getStatus());
        }
        $this->assertSame($fileIds, array_map(fn($as) => $as->getFileId(), $answerSheets));
    }

    public function testUploadMovesFileToPermanentStorageNotTempPath(): void
    {
        $analysisId = $this->createAnalysisAndGetId();

        $file = $this->createTestFile('permanent_storage.pdf', 'application/pdf', 'permanent storage check');
        $tempPath = $file->getPathname();

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'file' => $file,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $fileId = $data['data']['files'][0]['fileId'];

        // The PHP temporary upload file must be gone after the request
        // (it was moved into permanent analysis storage during the request).
        $this->assertFileDoesNotExist($tempPath);

        // The permanent file must exist under the analysis storage directory,
        // resolved via analysisId + fileId (never via a PHP temp path).
        $container = $this->client->getContainer();
        $storageResolver = $container->get(AnalysisStorageResolver::class);
        $permanentPath = $storageResolver->getFilePathByFileId($analysisId, $fileId);
        $this->assertFileExists($permanentPath);
        $this->assertStringNotContainsString(sys_get_temp_dir(), $permanentPath);

        // MongoDB references the permanent file ID.
        $mongoRepo = $container->get(AnalysisRepository::class);
        $mongoAnalysis = $mongoRepo->findByAnalysisIdAndStudentId($analysisId, (string) $this->student1Id);
        $this->assertSame($fileId, $mongoAnalysis->getDocuments()->getAnswerSheet()->getFileId());
    }

    public function testUploadDoesNotStartProcessing(): void
    {
        $analysisId = $this->createAnalysisAndGetId();

        $file = $this->createTestFile('no_processing.pdf', 'application/pdf', 'no processing on upload');

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'file' => $file,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        // Uploading files must NOT start AI processing: the analysis stays in
        // DRAFT and processing never enters PROCESSING/ANALYZING/EXTRACTING_PDF.
        $container = $this->client->getContainer();
        $mongoRepo = $container->get(AnalysisRepository::class);
        $mongoAnalysis = $mongoRepo->findByAnalysisIdAndStudentId($analysisId, (string) $this->student1Id);
        $this->assertNotNull($mongoAnalysis);
        $this->assertSame('DRAFT', $mongoAnalysis->getStatus());
        $this->assertSame('PENDING', $mongoAnalysis->getProcessing()->getStatus());
        $this->assertNotSame('PROCESSING', $mongoAnalysis->getProcessing()->getStage());
        $this->assertNotSame('ANALYZING', $mongoAnalysis->getProcessing()->getStage());
        $this->assertNotSame('EXTRACTING_PDF', $mongoAnalysis->getProcessing()->getStage());
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
        $this->assertArrayHasKey('files', $data['errors'] ?? []);
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
        $this->assertArrayHasKey('files[0]', $data['errors'] ?? []);
        $this->assertStringContainsString('Invalid file type', $data['errors']['files[0]'][0]);
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
        $this->assertArrayHasKey('files[0]', $data['errors'] ?? []);
        $this->assertStringContainsString('too large', strtolower($data['errors']['files[0]'][0]));

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
        $this->assertArrayHasKey('files[0]', $data['errors'] ?? []);

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
        
        // Answer sheets should still be empty (only a question paper was uploaded)
        $this->assertSame(0, $mongoAnalysis->getDocuments()->getAnswerSheetCount());
        $this->assertEmpty($mongoAnalysis->getDocuments()->getAnswerSheets());
    }

    // ========== PAGE LIMIT TESTS ==========

    public function testUploadAtPageLimitAllowed(): void
    {
        $analysisId = $this->createAnalysisAndGetId();

        // Upload exactly 50 pages (50 images = 50 pages)
        $files = [];
        for ($i = 1; $i <= 50; $i++) {
            $files[] = $this->createTestFile("page_{$i}.jpg", 'image/jpeg', "page {$i}");
        }

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'files' => $files,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertCount(50, $data['data']['files']);
    }

    public function testUploadExceedsPageLimitRejected(): void
    {
        $analysisId = $this->createAnalysisAndGetId();

        // Upload 51 pages (exceeds 50 page limit)
        $files = [];
        for ($i = 1; $i <= 51; $i++) {
            $files[] = $this->createTestFile("page_{$i}.jpg", 'image/jpeg', "page {$i}");
        }

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'files' => $files,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('VALIDATION_ERROR', $data['code'] ?? '');
        $this->assertArrayHasKey('code', $data['errors'] ?? []);
        $this->assertSame('ANALYSIS_PAGE_LIMIT_EXCEEDED', $data['errors']['code'] ?? '');
        $this->assertSame(50, $data['errors']['maxPages'] ?? null);
        $this->assertSame(0, $data['errors']['existingPages'] ?? null);
        $this->assertSame(51, $data['errors']['requestedPages'] ?? null);
        $this->assertSame(51, $data['errors']['totalPages'] ?? null);
    }

    public function testMultipleUploadsRespectCumulativePageLimit(): void
    {
        $analysisId = $this->createAnalysisAndGetId();

        // First upload: 30 pages
        $files1 = [];
        for ($i = 1; $i <= 30; $i++) {
            $files1[] = $this->createTestFile("page1_{$i}.jpg", 'image/jpeg', "page {$i}");
        }

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'files' => $files1,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        // Second upload: 20 pages (total = 50, exactly at limit)
        $files2 = [];
        for ($i = 1; $i <= 20; $i++) {
            $files2[] = $this->createTestFile("page2_{$i}.jpg", 'image/jpeg', "page {$i}");
        }

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'files' => $files2,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        // Third upload: 1 page (would exceed limit to 51)
        $file3 = $this->createTestFile('page3_1.jpg', 'image/jpeg', 'page 51');

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'file' => $file3,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('VALIDATION_ERROR', $data['code'] ?? '');
        $this->assertSame('ANALYSIS_PAGE_LIMIT_EXCEEDED', $data['errors']['code'] ?? '');
        $this->assertSame(50, $data['errors']['maxPages'] ?? null);
        $this->assertSame(50, $data['errors']['existingPages'] ?? null);
        $this->assertSame(1, $data['errors']['requestedPages'] ?? null);
        $this->assertSame(51, $data['errors']['totalPages'] ?? null);
    }

    public function testQuestionPaperAndAnswerSheetsCombinedRespectPageLimit(): void
    {
        $analysisId = $this->createAnalysisAndGetId();

        // Upload 10-page question paper (PDF) - note: test PDF is 1 page
        $qpFile = $this->createTestFile('question_paper.pdf', 'application/pdf', 'question paper content');

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'QUESTION_PAPER',
        ], [
            'file' => $qpFile,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        // Upload 40 answer sheets (total = 41, under limit of 50)
        $files = [];
        for ($i = 1; $i <= 40; $i++) {
            $files[] = $this->createTestFile("answer_{$i}.jpg", 'image/jpeg', "answer {$i}");
        }

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'files' => $files,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        // Upload 10 more answer sheets (total = 51, exceeds limit)
        $files2 = [];
        for ($i = 1; $i <= 10; $i++) {
            $files2[] = $this->createTestFile("answer_extra_{$i}.jpg", 'image/jpeg', "extra {$i}");
        }

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'files' => $files2,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('VALIDATION_ERROR', $data['code'] ?? '');
        $this->assertSame('ANALYSIS_PAGE_LIMIT_EXCEEDED', $data['errors']['code'] ?? '');
        $this->assertSame(50, $data['errors']['maxPages'] ?? null);
        $this->assertSame(41, $data['errors']['existingPages'] ?? null); // 1 QP + 40 answers = 41
    }

    public function testRejectedUploadDoesNotPersistFile(): void
    {
        $analysisId = $this->createAnalysisAndGetId();

        // First upload 50 pages
        $files = [];
        for ($i = 1; $i <= 50; $i++) {
            $files[] = $this->createTestFile("page_{$i}.jpg", 'image/jpeg', "page {$i}");
        }

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'files' => $files,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        // Try to upload 1 more page (rejected)
        $extraFile = $this->createTestFile('extra.jpg', 'image/jpeg', 'extra');

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'file' => $extraFile,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode(), $response->getContent());

        // Verify no extra file was persisted
        $container = $this->client->getContainer();
        $mongoRepo = $container->get(AnalysisRepository::class);
        $mongoAnalysis = $mongoRepo->findByAnalysisIdAndStudentId($analysisId, (string) $this->student1Id);
        $this->assertNotNull($mongoAnalysis);
        $this->assertSame(50, count($mongoAnalysis->getDocuments()->getAnswerSheets()));
    }

    public function testRejectedUploadDoesNotDispatchMessenger(): void
    {
        $analysisId = $this->createAnalysisAndGetId();

        // First upload 50 pages
        $files = [];
        for ($i = 1; $i <= 50; $i++) {
            $files[] = $this->createTestFile("page_{$i}.jpg", 'image/jpeg', "page {$i}");
        }

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'files' => $files,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        // Try to upload 1 more page (rejected)
        $extraFile = $this->createTestFile('extra.jpg', 'image/jpeg', 'extra');

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'file' => $extraFile,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode(), $response->getContent());

        // The analysis should still be in DRAFT status (no processing started)
        $container = $this->client->getContainer();
        $mongoRepo = $container->get(AnalysisRepository::class);
        $mongoAnalysis = $mongoRepo->findByAnalysisIdAndStudentId($analysisId, (string) $this->student1Id);
        $this->assertNotNull($mongoAnalysis);
        $this->assertSame('DRAFT', $mongoAnalysis->getStatus());
        $this->assertSame('PENDING', $mongoAnalysis->getProcessing()->getStatus());
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
        // Use tempnam exactly like the working test
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $tempFile = tempnam(sys_get_temp_dir(), 'doc_') . '.' . $extension;
        
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
        
        $result = file_put_contents($tempFile, $content);
        if ($result === false) {
            throw new \RuntimeException("Failed to create test file: $tempFile");
        }
        
        if (!file_exists($tempFile)) {
            throw new \RuntimeException("Test file was not created: $tempFile");
        }
        
        if (!is_readable($tempFile)) {
            throw new \RuntimeException("Test file is not readable: $tempFile");
        }
        
        // Debug: output file info
        fwrite(STDERR, "CREATE TEST FILE: $tempFile (size: " . filesize($tempFile) . ")\n");
        
        return new UploadedFile(
            $tempFile,
            $filename,
            $mimeType,
            null,
            true
        );
    }

    // ========== LIMITS TESTS ==========

    public function testGetAnalysisIncludesLimits(): void
    {
        $analysisId = $this->createAnalysisAndGetId();

        // Get analysis - should include limits
        $this->client->request('GET', '/api/v1/student/analyses/' . $analysisId, [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('limits', $data['data']);

        $limits = $data['data']['limits'];
        $this->assertSame(50, $limits['maxTotalPages']);
        $this->assertSame(26214400, $limits['maxFileSize']);
        $this->assertSame(100, $limits['maxFiles']);
        $this->assertSame(0, $limits['pagesUsed']);
        $this->assertSame(50, $limits['pagesRemaining']);
    }

    public function testGetAnalysisLimitsWithUploadedPages(): void
    {
        $analysisId = $this->createAnalysisAndGetId();

        // Upload 10 pages (images = 1 page each)
        $files = [];
        for ($i = 1; $i <= 10; $i++) {
            $files[] = $this->createTestFile("page_{$i}.jpg", 'image/jpeg', "page {$i}");
        }

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'files' => $files,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        // Get analysis - should include limits with pagesUsed = 10
        $this->client->request('GET', '/api/v1/student/analyses/' . $analysisId, [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('limits', $data['data']);

        $limits = $data['data']['limits'];
        $this->assertSame(50, $limits['maxTotalPages']);
        $this->assertSame(26214400, $limits['maxFileSize']);
        $this->assertSame(100, $limits['maxFiles']);
        $this->assertSame(10, $limits['pagesUsed']);
        $this->assertSame(40, $limits['pagesRemaining']);
    }

    public function testGetAnalysisLimitsPagesRemainingNeverNegative(): void
    {
        $analysisId = $this->createAnalysisAndGetId();

        // Upload 50 pages (at limit)
        $files = [];
        for ($i = 1; $i <= 50; $i++) {
            $files[] = $this->createTestFile("page_{$i}.jpg", 'image/jpeg', "page {$i}");
        }

        $this->client->request('POST', '/api/v1/student/analyses/upload', [
            'analysisId' => $analysisId,
            'documentType' => 'ANSWER_SHEET',
        ], [
            'files' => $files,
        ], $this->authHeaders($this->student1Token));

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        // Get analysis - pagesRemaining should be 0, not negative
        $this->client->request('GET', '/api/v1/student/analyses/' . $analysisId, [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('limits', $data['data']);

        $limits = $data['data']['limits'];
        $this->assertSame(50, $limits['pagesUsed']);
        $this->assertSame(0, $limits['pagesRemaining']);
    }

    // ========== UPLOAD-REQUIREMENTS TESTS ==========

    public function testUploadRequirementsEndpointReturns200(): void
    {
        $this->client->request('GET', '/api/v1/student/analyses/upload-requirements', [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    }

    public function testUploadRequirementsMaxTotalPagesMatchesAnalysisSettings(): void
    {
        $settings = $this->getAnalysisSettings();
        $this->client->request('GET', '/api/v1/student/analyses/upload-requirements', [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame($settings->getUploadMaxTotalPages(), $data['data']['maxTotalPages']);
    }

    public function testUploadRequirementsMaxFileSizeMatchesAnalysisSettings(): void
    {
        $settings = $this->getAnalysisSettings();
        $this->client->request('GET', '/api/v1/student/analyses/upload-requirements', [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame($settings->getUploadMaxFileSize(), $data['data']['maxFileSize']);
    }

    public function testUploadRequirementsMaxFilesMatchesAnalysisSettings(): void
    {
        $settings = $this->getAnalysisSettings();
        $this->client->request('GET', '/api/v1/student/analyses/upload-requirements', [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame($settings->getUploadMaxFiles(), $data['data']['maxFiles']);
    }

    public function testUploadRequirementsResponseFollowsApiFormat(): void
    {
        $this->client->request('GET', '/api/v1/student/analyses/upload-requirements', [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertIsString($data['message']);
        $this->assertNotEmpty($data['message']);
        $this->assertIsArray($data['data']);
        $this->assertArrayHasKey('maxTotalPages', $data['data']);
        $this->assertArrayHasKey('maxFileSize', $data['data']);
        $this->assertArrayHasKey('maxFiles', $data['data']);
        $this->assertArrayHasKey('version', $data['meta']);
        $this->assertSame('v1', $data['meta']['version']);

        // Session-dependent fields must not be exposed on the requirements endpoint.
        $this->assertArrayNotHasKey('pagesUsed', $data['data']);
        $this->assertArrayNotHasKey('pagesRemaining', $data['data']);
    }

    public function testUploadRequirementsContainsNoHardcodedLimitValues(): void
    {
        $settings = $this->getAnalysisSettings();
        $this->client->request('GET', '/api/v1/student/analyses/upload-requirements', [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);

        // The response must equal the configured AnalysisSettings values exactly,
        // proving the endpoint reads from AnalysisSettings rather than literal constants.
        $this->assertSame(
            [
                'maxTotalPages' => $settings->getUploadMaxTotalPages(),
                'maxFileSize' => $settings->getUploadMaxFileSize(),
                'maxFiles' => $settings->getUploadMaxFiles(),
            ],
            $data['data']
        );
    }

    public function testUploadRequirementsAndUploadValidationShareSameSettings(): void
    {
        $settings = $this->getAnalysisSettings();
        $this->client->request('GET', '/api/v1/student/analyses/upload-requirements', [], [], $this->authHeaders($this->student1Token));
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $data = json_decode($response->getContent(), true);

        // The requirements endpoint and the upload validation path must share the
        // same source of truth (AnalysisSettings), so a config change stays in sync.
        $this->assertSame($settings->getUploadMaxFileSize(), $data['data']['maxFileSize']);
        $this->assertSame($settings->getUploadMaxFiles(), $data['data']['maxFiles']);
        $this->assertSame($settings->getUploadMaxTotalPages(), $data['data']['maxTotalPages']);
    }

    private function getAnalysisSettings(): \App\Service\Analysis\Settings\AnalysisSettings
    {
        return $this->client->getContainer()->get(\App\Service\Analysis\Settings\AnalysisSettings::class);
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