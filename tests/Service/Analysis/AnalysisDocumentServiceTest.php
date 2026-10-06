<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis;

use App\Document\Analysis;
use App\Document\Documents;
use App\Document\Exam;
use App\Document\Processing;
use App\DTO\Analysis\UploadAnalysisDocumentRequestDTO;
use App\Entity\User;
use App\Exception\AnalysisNotFoundException;
use App\Message\Analysis\ProcessAnalysisMessage;
use App\Repository\AnalysisRepository;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use App\Service\Analysis\AnalysisDocumentService;
use App\Service\Analysis\AnalysisStorageResolver;
use App\Service\Analysis\AnalysisWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class AnalysisDocumentServiceTest extends TestCase
{
    private const PDF_CONTENT = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> /Contents 4 0 R >>\nendobj\n4 0 obj\n<< /Length 44 >>\nstream\nBT /F1 12 Tf 100 700 Td (Test PDF) Tj ET\nendstream\nendobj\nxref\n0 5\n0000000000 65535 f \n0000000010 00000 n \n0000000060 00000 n \n0000000117 00000 n \n0000000216 00000 n \ntrailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n293\n%%EOF";

    private function createMongoAnalysis(): Analysis
    {
        $analysis = new Analysis();
        $analysis->setAnalysisId('test-analysis-id');
        $analysis->setStudentId('test-student-id');
        $analysis->setStatus('DRAFT');
        $analysis->setDocuments(new Documents());
        $analysis->setExam(new Exam());
        $analysis->setProcessing(new Processing());

        return $analysis;
    }

    private function createService(
        ?Analysis $mongoAnalysis,
        ?\App\Entity\Analysis $pgAnalysis,
        MessageBusInterface $messageBus,
        AnalysisStorageResolver $storageResolver
    ): AnalysisDocumentService {
        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $pgRepo = $this->createMock(AnalysisRepository::class);
        $pgRepo->method('findByAnalysisIdAndStudent')->willReturn($pgAnalysis);

        return new AnalysisDocumentService(
            $this->createMock(EntityManagerInterface::class),
            $pgRepo,
            $mongoRepo,
            $this->createMock(LoggerInterface::class),
            $messageBus,
            $storageResolver,
            $this->createMock(AnalysisWorkflowService::class),
        );
    }

    private function createTempUpload(string $name, string $content): UploadedFile
    {
        $path = sys_get_temp_dir() . '/' . uniqid('upload_', true) . '.pdf';
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    public function testUploadDocumentStoresFilesInPermanentStorageAndDispatchesOnePrepareMessage(): void
    {
        $uploadDir = sys_get_temp_dir() . '/analysis_upload_' . uniqid();
        $storageResolver = $this->createMock(AnalysisStorageResolver::class);
        $storageResolver->method('ensureAnalysisDirectory')->willReturn($uploadDir);

        // TEST 1 + 2: exactly ONE prepare message for 3 files (not one per file).
        $dispatched = [];
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;
                return new Envelope($message);
            }
        );

        $student = new User();
        $mongoAnalysis = $this->createMongoAnalysis();
        $pgAnalysis = new \App\Entity\Analysis();

        $service = $this->createService($mongoAnalysis, $pgAnalysis, $messageBus, $storageResolver);

        $tempPaths = [];
        $files = [];
        for ($i = 1; $i <= 3; $i++) {
            $file = $this->createTempUpload("page_{$i}.pdf", self::PDF_CONTENT);
            $tempPaths[] = $file->getPathname();
            $files[] = $file;
        }

        $dto = new UploadAnalysisDocumentRequestDTO(
            files: $files,
            documentType: 'ANSWER_SHEET',
        );

        $result = $service->uploadDocument($student, 'test-analysis-id', $dto);

        // TEST 1: one prepare message dispatched.
        $this->assertCount(1, $dispatched, 'Uploading 3 files must dispatch exactly ONE prepare message');

        // TEST 3: message carries analysisId + prepare action only.
        $message = $dispatched[0];
        $this->assertInstanceOf(ProcessAnalysisMessage::class, $message);
        $this->assertSame('test-analysis-id', $message->analysisId);
        $this->assertSame((string) $student->getId(), $message->studentId);
        $this->assertSame('prepare', $message->action);
        $this->assertFalse($message->isRetry);

        // TEST 4: no UploadedFile / temp path in the message.
        $serialized = serialize($message);
        $this->assertStringNotContainsString($uploadDir, $serialized);
        foreach ($tempPaths as $tempPath) {
            $this->assertStringNotContainsString($tempPath, $serialized);
        }
        $this->assertStringNotContainsString(UploadedFile::class, $serialized);

        // Preparation lifecycle: existing preparation stage + PENDING.
        $processing = $mongoAnalysis->getProcessing();
        $this->assertSame('UPLOADED', $processing->getStage());
        $this->assertSame('PENDING', $processing->getStatus());

        // All 3 files stored with separate IDs and preserved ordering
        $this->assertSame('test-analysis-id', $result['analysisId']);
        $this->assertSame('ANSWER_SHEET', $result['documentType']);
        $this->assertCount(3, $result['files']);

        $fileIds = [];
        foreach ($result['files'] as $index => $file) {
            $this->assertNotEmpty($file['fileId']);
            $this->assertSame($index + 1, $file['page']);
            $this->assertSame('UPLOADED', $file['status']);
            $fileIds[] = $file['fileId'];
        }
        $this->assertSame(array_values(array_unique($fileIds)), $fileIds);

        // Each PHP temporary upload file was moved away during the request
        foreach ($tempPaths as $tempPath) {
            $this->assertFileDoesNotExist($tempPath);
        }

        // Permanent files exist in the storage directory, named by file ID
        foreach ($result['files'] as $file) {
            $permanentPath = $uploadDir . DIRECTORY_SEPARATOR . $file['fileId'] . '.pdf';
            $this->assertFileExists($permanentPath);
            $this->assertSame(self::PDF_CONTENT, file_get_contents($permanentPath));
        }

        // MongoDB document references all 3 permanent file IDs in order
        $answerSheets = $mongoAnalysis->getDocuments()->getAnswerSheets();
        $this->assertCount(3, $answerSheets);
        $this->assertSame($fileIds, array_map(fn($as) => $as->getFileId(), $answerSheets));
        foreach ($answerSheets as $index => $answerSheet) {
            $this->assertSame($index + 1, $answerSheet->getPage());
            $this->assertSame('UPLOADED', $answerSheet->getStatus());
        }

        // Cleanup
        foreach ($result['files'] as $file) {
            @unlink($uploadDir . DIRECTORY_SEPARATOR . $file['fileId'] . '.pdf');
        }
        @rmdir($uploadDir);
    }

    public function testUploadQuestionPaperDoesNotDispatchPrepareMessage(): void
    {
        $uploadDir = sys_get_temp_dir() . '/analysis_upload_' . uniqid();
        $storageResolver = $this->createMock(AnalysisStorageResolver::class);
        $storageResolver->method('ensureAnalysisDirectory')->willReturn($uploadDir);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $student = new User();
        $mongoAnalysis = $this->createMongoAnalysis();

        $service = $this->createService($mongoAnalysis, new \App\Entity\Analysis(), $messageBus, $storageResolver);

        $file = $this->createTempUpload('qp.pdf', self::PDF_CONTENT);
        $dto = new UploadAnalysisDocumentRequestDTO(
            files: [$file],
            documentType: 'QUESTION_PAPER',
        );

        $result = $service->uploadDocument($student, 'test-analysis-id', $dto);

        $this->assertCount(1, $result['files']);
        $this->assertFileExists($uploadDir . DIRECTORY_SEPARATOR . $result['files'][0]['fileId'] . '.pdf');
        $this->assertFileDoesNotExist($file->getPathname());

        // Question paper is stored on the document, answer sheets stay empty
        $questionPaper = $mongoAnalysis->getDocuments()->getQuestionPaper();
        $this->assertSame($result['files'][0]['fileId'], $questionPaper->getFileId());
        $this->assertSame('UPLOADED', $questionPaper->getStatus());
        $this->assertSame(0, $mongoAnalysis->getDocuments()->getAnswerSheetCount());

        @unlink($uploadDir . DIRECTORY_SEPARATOR . $result['files'][0]['fileId'] . '.pdf');
        @rmdir($uploadDir);
    }

    public function testUploadDocumentRejectsNonOwner(): void
    {
        $uploadDir = sys_get_temp_dir() . '/analysis_upload_' . uniqid();
        $storageResolver = $this->createMock(AnalysisStorageResolver::class);
        $storageResolver->method('ensureAnalysisDirectory')->willReturn($uploadDir);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $student = new User();
        $mongoAnalysis = $this->createMongoAnalysis();

        // PostgreSQL ownership check fails (analysis not found for this student)
        $service = $this->createService($mongoAnalysis, null, $messageBus, $storageResolver);

        $file = $this->createTempUpload('page.pdf', self::PDF_CONTENT);
        $dto = new UploadAnalysisDocumentRequestDTO(
            files: [$file],
            documentType: 'ANSWER_SHEET',
        );

        try {
            $service->uploadDocument($student, 'test-analysis-id', $dto);
            $this->fail('Expected AnalysisNotFoundException');
        } catch (AnalysisNotFoundException) {
            // expected
        }

        // Nothing was stored, moved or dispatched
        $this->assertFileExists($file->getPathname());
        $this->assertEmpty(glob($uploadDir . '/*'));

        @unlink($file->getPathname());
        @rmdir($uploadDir);
    }

    public function testUploadDocumentRejectsInvalidMimeType(): void
    {
        $uploadDir = sys_get_temp_dir() . '/analysis_upload_' . uniqid();
        $storageResolver = $this->createMock(AnalysisStorageResolver::class);
        $storageResolver->method('ensureAnalysisDirectory')->willReturn($uploadDir);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $student = new User();
        $mongoAnalysis = $this->createMongoAnalysis();
        $pgAnalysis = new \App\Entity\Analysis();

        $service = $this->createService($mongoAnalysis, $pgAnalysis, $messageBus, $storageResolver);

        $path = sys_get_temp_dir() . '/' . uniqid('upload_', true) . '.txt';
        file_put_contents($path, 'not an allowed document type');
        $file = new UploadedFile($path, 'page.txt', 'text/plain', null, true);

        $dto = new UploadAnalysisDocumentRequestDTO(
            files: [$file],
            documentType: 'ANSWER_SHEET',
        );

        try {
            $service->uploadDocument($student, 'test-analysis-id', $dto);
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid file type', $e->getMessage());
        }

        // Nothing was stored or moved
        $this->assertFileExists($path);
        $this->assertEmpty(glob($uploadDir . '/*'));

        @unlink($path);
        @rmdir($uploadDir);
    }
}
