<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis;

use App\Document\Analysis;
use App\Document\Documents;
use App\Document\Exam;
use App\Document\Processing;
use App\DTO\Analysis\Direct\CurrentExamAnalysis;
use App\DTO\Analysis\Direct\DirectExtractionResult;
use App\DTO\Analysis\Direct\ConceptAssessment;
use App\DTO\Analysis\Direct\Mistake;
use App\DTO\Analysis\Direct\EvidenceReference;
use App\DTO\Analysis\Direct\Performance;
use App\DTO\Analysis\ExtractedAnswerSheetMetadata;
use App\Exception\AnalysisNotFoundException;
use App\Exception\AnalysisLifecycleException;
use App\Repository\AnalysisRepository;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use App\Repository\MongoDB\OcrCacheRepository;
use App\Service\Analysis\DirectAiAnalysisService;
use App\Service\Analysis\Processing\OpenAiDirectDocumentProvider;
use App\Service\Analysis\Processing\DocumentTextExtractorInterface;
use App\Service\Analysis\Processing\AnswerSheetExtractor;
use App\Service\Analysis\Processing\ProvenanceValue;
use App\Service\Analysis\Processing\OpenAiSanitizer;
use App\Service\Analysis\AnalysisStorageResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use PHPUnit\Framework\TestCase;

class DirectAiAnalysisServiceTest extends TestCase
{
private function createService(
        MongoAnalysisRepository $mongoRepo,
        ?OpenAiDirectDocumentProvider $provider = null,
        ?AnalysisStorageResolver $storageResolver = null,
        ?AnalysisRepository $pgRepo = null,
        ?DocumentTextExtractorInterface $textExtractor = null,
        ?AnswerSheetExtractor $answerSheetExtractor = null,
    ): DirectAiAnalysisService {
        return new DirectAiAnalysisService(
            $this->createMock(EntityManagerInterface::class),
            $pgRepo ?? $this->createMock(AnalysisRepository::class),
            $mongoRepo,
            $this->createMock(OcrCacheRepository::class),
            $provider ?? $this->createMock(OpenAiDirectDocumentProvider::class),
            $storageResolver ?? $this->createMock(AnalysisStorageResolver::class),
            $textExtractor ?? $this->createMock(DocumentTextExtractorInterface::class),
            $answerSheetExtractor ?? $this->createMock(AnswerSheetExtractor::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(MessageBusInterface::class),
            $this->createMock(OpenAiSanitizer::class),
            $this->createMock(HttpClientInterface::class),
            'test-api-key',
        );
    }
    private function createAnalysis(string $status = 'CONFIRMED', bool $hasAiAnalysis = false): Analysis
    {
        $analysis = new Analysis();
        $analysis->setAnalysisId('test-analysis-id');
        $analysis->setStudentId('test-student-id');
        $analysis->setStatus($status);
        $analysis->setDocuments(new Documents());
        $analysis->getDocuments()->addAnswerSheet(new \App\Document\DocumentFile());
        $analysis->setExam(new Exam());
        $analysis->setProcessing(new Processing());

        if ($hasAiAnalysis) {
            $analysis->setAiAnalysis(['currentExamAnalysis' => ['strengths' => ['test']]]);
        }

        return $analysis;
    }

    public function testRunDirectAnalysisThrowsWhenAnalysisNotFound(): void
    {
        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn(null);

        $service = $this->createService($mongoRepo);

        $this->expectException(AnalysisNotFoundException::class);
        $service->runDirectAnalysis('missing-id', 'student-id');
    }

    public function testRunDirectAnalysisThrowsOnDoneStatus(): void
    {
        $mongoAnalysis = $this->createAnalysis('DONE', true);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $service = $this->createService($mongoRepo);

        $this->expectException(AnalysisLifecycleException::class);
        $service->runDirectAnalysis('test-analysis-id', 'test-student-id');
    }

    public function testRunDirectAnalysisThrowsOnInvalidStatus(): void
    {
        $mongoAnalysis = $this->createAnalysis('DRAFT');

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $service = $this->createService($mongoRepo);

        $this->expectException(AnalysisLifecycleException::class);
        $service->runDirectAnalysis('test-analysis-id', 'test-student-id');
    }

    public function testRunDirectAnalysisThrowsWhenConfirmedButNoConfirmedMetadata(): void
    {
        // Analysis is marked as CONFIRMED but PostgreSQL has no metadata
        $mongoAnalysis = $this->createAnalysis('CONFIRMED');
        $mongoAnalysis->getDocuments()->getAnswerSheet()->setStatus('UPLOADED');
        $mongoAnalysis->getDocuments()->getAnswerSheet()->setFileId('test-file-id');
        // Do NOT set MongoDB metadataConfirmationStatus - simulate unconfirmed metadata

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $pgRepo = $this->createMock(AnalysisRepository::class);
        $pgRepo->method('findByAnalysisId')->willReturn(null); // No PostgreSQL entity = no confirmed metadata

        $storageResolver = $this->createMock(AnalysisStorageResolver::class);
        $storageResolver->method('getFilePathByFileId')
            ->willReturnCallback(static fn(string $analysisId, string $fileId): string => '/tmp/' . $fileId . '.pdf');

        $service = $this->createService($mongoRepo, null, $storageResolver, $pgRepo);

        $this->expectException(AnalysisLifecycleException::class);
        $this->expectExceptionMessage('Confirmed metadata is required before analysis.');
        $service->runDirectAnalysis('test-analysis-id', 'test-student-id');
    }

    public function testRunDirectAnalysisThrowsWhenNoExtractedMetadata(): void
    {
        $mongoAnalysis = $this->createAnalysis('NEEDS_REVIEW');
        $mongoAnalysis->getDocuments()->addAnswerSheet(new \App\Document\DocumentFile());
        $answerSheet = $mongoAnalysis->getDocuments()->getAnswerSheet();
        $answerSheet->setStatus('UPLOADED');
        $answerSheet->setFileId('file-1');
        $answerSheet->setOriginalFilename('page-1.png');
        $mongoAnalysis->getExam()->setExtractedMetadata(['studentName' => 'Test Student']);
        $mongoAnalysis->setMetadataConfirmationStatus('CONFIRMED');
        $mongoAnalysis->setAnswerSheetFileHash('precomputed-hash');

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $pgAnalysis = $this->createMock(\App\Entity\Analysis::class);
        $pgAnalysis->method('getMetadataConfirmationStatus')->willReturn('CONFIRMED');
        $pgAnalysis->method('getSubjectName')->willReturn('Mathematics');
        $pgAnalysis->method('getTotalMarks')->willReturn(30);

        $pgRepo = $this->createMock(AnalysisRepository::class);
        $pgRepo->method('findByAnalysisId')->willReturn($pgAnalysis);

        $storageResolver = $this->createMock(AnalysisStorageResolver::class);
        $storageResolver->method('getFilePathByFileId')->willReturn('/tmp/file-1.png');

        $capturedPaths = null;
        $provider = $this->createMock(OpenAiDirectDocumentProvider::class);
        $provider->method('extractDocumentsWithPreliminaryAnalysis')->willReturnCallback(
            function (array|string $answerSheetPaths, ?string $questionPaperPath, array $trustedMetadata) use (&$capturedPaths) {
                $capturedPaths = $answerSheetPaths;
                throw new \RuntimeException('CAPTURED');
            }
        );

        $service = $this->createService($mongoRepo, $provider, $storageResolver, $pgRepo);

        try {
            $service->runDirectAnalysis('test-analysis-id', 'test-student-id');
            $this->fail('Expected provider invocation to be captured');
        } catch (\RuntimeException $e) {
            $this->assertSame('CAPTURED', $e->getMessage());
        }

        $this->assertSame('/tmp/file-1.png', $capturedPaths);
    }

    public function testRunDirectExtractionRunsSetPdfAndPersistsExtraction(): void
    {
        $mongoAnalysis = $this->createAnalysis('DRAFT');
        // createAnalysis() already registers one answer sheet - add one more
        // so this analysis owns exactly two ordered pages.
        $mongoAnalysis->getDocuments()->addAnswerSheet(new \App\Document\DocumentFile());
        $answerSheets = $mongoAnalysis->getDocuments()->getAnswerSheets();
        $this->assertCount(2, $answerSheets);

        $fileIds = ['file-1', 'file-2'];
        foreach ($answerSheets as $index => $answerSheet) {
            $answerSheet->setStatus('UPLOADED');
            $answerSheet->setFileId($fileIds[$index]);
            $answerSheet->setMimeType('application/pdf');
            $answerSheet->setPage($index + 1);
        }

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $storageResolver = $this->createMock(AnalysisStorageResolver::class);
        $storageResolver->method('getFilePathByFileId')->willReturnCallback(
            static fn(string $analysisId, string $fileId): string => '/permanent/' . $fileId . '.pdf'
        );

        // TEST 3: DocumentTextExtractor is invoked for EVERY answer sheet.
        $extractedPaths = [];
        $textExtractor = $this->createMock(DocumentTextExtractorInterface::class);
        $textExtractor->method('supportsMimeType')->willReturn(true);
        $textExtractor->method('extract')->willReturnCallback(
            function (string $filePath, string $mimeType) use (&$extractedPaths): array {
                $extractedPaths[] = $filePath;
                return [
                    'text' => str_repeat('Student Name: Test Student Roll: 12 ', 5),
                    'pages' => [['page' => 1, 'text' => 'page body']],
                    'pageCount' => 1,
                    'hasExtractableText' => true,
                    'extractionMethod' => 'pdf_text',
                ];
            }
        );

        // TEST 4: AnswerSheetExtractor returns the structured result.
        $answerSheetExtractor = $this->createMock(AnswerSheetExtractor::class);
        $answerSheetExtractor->method('extractFromAnswerSheet')->willReturn([
            'metadata' => new ExtractedAnswerSheetMetadata(
                studentName: ProvenanceValue::extracted('Test Student', 0.85),
                rollNumber: ProvenanceValue::extracted('12', 0.85),
            ),
            'answers' => [
                new \App\DTO\Analysis\ExtractedAnswer(
                    questionNumber: '1',
                    answerText: 'Answer body for file 1',
                    confidence: 0.9,
                    sourcePage: '1',
                ),
                new \App\DTO\Analysis\ExtractedAnswer(
                    questionNumber: '1',
                    answerText: 'Answer body for file 2',
                    confidence: 0.9,
                    sourcePage: '2',
                ),
            ],
            'questions' => [
                [
                    'questionNumber' => '1',
                    'questionText' => 'Question 1',
                    'marks' => 10,
                    'confidence' => 0.9,
                ],
            ],
            'extractionResult' => 'SUCCESS',
            'extractionConfidence' => 0.82,
        ]);

        // TEST 8: prepare must never start Direct AI analysis.
        $provider = $this->createMock(OpenAiDirectDocumentProvider::class);
        $provider->expects($this->never())->method('extractDocumentsWithPreliminaryAnalysis');
        $provider->expects($this->never())->method('analyzeExtractedData');

        $service = $this->createService($mongoRepo, $provider, $storageResolver, null, $textExtractor, $answerSheetExtractor);

        $service->runDirectExtraction('test-analysis-id', 'test-student-id');

        // TEST 7: all files processed, in page order, using permanent paths.
        $this->assertSame(
            ['/permanent/file-1.pdf', '/permanent/file-2.pdf'],
            $extractedPaths
        );

        // TEST 5 + 6: extraction persisted and analysis ready for metadata review.
        $exam = $mongoAnalysis->getExam();
        $this->assertNotEmpty($exam->getExtractedMetadata());
        $this->assertSame('Test Student', $exam->getExtractedMetadata()['studentName']['value']);
        $this->assertSame('12', $exam->getExtractedMetadata()['rollNumber']['value']);
        $this->assertSame('SUCCESS', $exam->getExtractionResult());
        // Confidence is now the combined score across files/answers/metadata,
        // not the single-file extractor value.
        $this->assertGreaterThan(0.0, $exam->getExtractionConfidence());
        $this->assertLessThanOrEqual(1.0, $exam->getExtractionConfidence());
        $this->assertNotNull($exam->getExtractedAt());
        $this->assertSame('SETA_PDF', $exam->getExtractionMethod());
        // The extractor stub returns one answer per file, and the analysis has
        // two answer-sheet files, so both must be retained.
        $this->assertCount(2, $exam->getExtractedAnswers());
        $this->assertSame('file-1', $exam->getExtractedAnswers()[0]['source']['fileId']);
        $this->assertSame('file-2', $exam->getExtractedAnswers()[1]['source']['fileId']);

        $processing = $mongoAnalysis->getProcessing();
        $this->assertSame('READY_FOR_REVIEW', $processing->getStage());
        $this->assertSame('NEEDS_REVIEW', $processing->getStatus());
        $this->assertSame('NEEDS_REVIEW', $mongoAnalysis->getStatus());
    }

    public function testRunDirectExtractionMarksFailedWithoutStartingAiOnSetPdfError(): void
    {
        $mongoAnalysis = $this->createAnalysis('DRAFT');
        $mongoAnalysis->getDocuments()->getAnswerSheet()->setStatus('UPLOADED');
        $mongoAnalysis->getDocuments()->getAnswerSheet()->setFileId('file-1');
        $mongoAnalysis->getDocuments()->getAnswerSheet()->setMimeType('application/pdf');
        $mongoAnalysis->getDocuments()->getAnswerSheet()->setPage(1);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        // Real PostgreSQL entity so the synced status can be asserted.
        $pgAnalysis = new \App\Entity\Analysis();
        $pgAnalysis->setStatus('PROCESSING');

        $pgRepo = $this->createMock(AnalysisRepository::class);
        $pgRepo->method('findByAnalysisId')->willReturn($pgAnalysis);

        $storageResolver = $this->createMock(AnalysisStorageResolver::class);
        $storageResolver->method('getFilePathByFileId')->willReturn('/permanent/file-1.pdf');

        $textExtractor = $this->createMock(DocumentTextExtractorInterface::class);
        $textExtractor->method('supportsMimeType')->willReturn(true);
        $textExtractor->method('extract')->willThrowException(new \RuntimeException('SetaPDF parser exploded'));

        $provider = $this->createMock(OpenAiDirectDocumentProvider::class);
        $provider->expects($this->never())->method('extractDocumentsWithPreliminaryAnalysis');
        $provider->expects($this->never())->method('analyzeExtractedData');

        $service = new DirectAiAnalysisService(
            $this->createMock(EntityManagerInterface::class),
            $pgRepo,
            $mongoRepo,
            $this->createMock(OcrCacheRepository::class),
            $provider,
            $storageResolver,
            $this->createMock(LoggerInterface::class),
            $this->createMock(MessageBusInterface::class),
            $textExtractor,
            $this->createMock(AnswerSheetExtractor::class),
        );

        $service->runDirectExtraction('test-analysis-id', 'test-student-id');

        $processing = $mongoAnalysis->getProcessing();
        $this->assertSame('EXTRACTION_FAILED', $processing->getStage());
        $this->assertSame('FAILED', $processing->getStatus());
        $this->assertSame('NEEDS_REVIEW', $mongoAnalysis->getStatus());
        // PostgreSQL must also leave PROCESSING, otherwise metadata
        // confirmation rejects the analysis with a business-rule violation.
        $this->assertSame('NEEDS_REVIEW', $pgAnalysis->getStatus());
    }

    /**
     * Regression: two answer-sheet files whose question numbers COLLIDE
     * (file 1 = Q1..Q6, file 2 = Q1..Q3) must both contribute answers.
     *
     * Previously the pages of every file were concatenated into one array and
     * passed to the extractor once; the extractor keys its question map by
     * question number, so file 2's Q1..Q3 silently replaced file 1's and only
     * the first file's answers were persisted.
     */
    public function testRunDirectExtractionAggregatesAnswersAcrossFilesWithCollidingQuestionNumbers(): void
    {
        $mongoAnalysis = $this->createAnalysis('DRAFT');
        $mongoAnalysis->getDocuments()->addAnswerSheet(new \App\Document\DocumentFile());

        $answerSheets = $mongoAnalysis->getDocuments()->getAnswerSheets();
        $this->assertCount(2, $answerSheets);

        $filenames = [
            'sample_answer_sheet_6_questions.pdf',
            'sample_answer_sheet_3_science.pdf',
        ];
        foreach ($answerSheets as $index => $answerSheet) {
            $answerSheet->setStatus('UPLOADED');
            $answerSheet->setFileId('file-' . ($index + 1));
            $answerSheet->setStoredFilename('file-' . ($index + 1) . '.pdf');
            $answerSheet->setOriginalFilename($filenames[$index]);
            $answerSheet->setMimeType('application/pdf');
            $answerSheet->setPage($index + 1);
        }

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $storageResolver = $this->createMock(AnalysisStorageResolver::class);
        $storageResolver->method('getFilePathByFileId')->willReturnCallback(
            static fn(string $analysisId, string $fileId): string => sys_get_temp_dir() . '/' . $fileId . '.pdf'
        );

        // File 1 exposes six "Question N ... Answer: ..." blocks, file 2 three.
        $texts = [
            'file-1' => $this->answerSheetText(6),
            'file-2' => $this->answerSheetText(3),
        ];
        foreach ($texts as $fileId => $text) {
            file_put_contents(sys_get_temp_dir() . '/' . $fileId . '.pdf', $text);
        }

        $textExtractor = $this->createMock(DocumentTextExtractorInterface::class);
        $textExtractor->method('supportsMimeType')->willReturn(true);
        $textExtractor->method('extract')->willReturnCallback(
            static function (string $filePath, string $mimeType) use ($texts): array {
                $fileId = basename($filePath, '.pdf');

                return [
                    'text' => $texts[$fileId] ?? '',
                    'pages' => [['page' => 1, 'text' => $texts[$fileId] ?? '']],
                    'pageCount' => 1,
                    'hasExtractableText' => true,
                    'extractionMethod' => 'pdf_text',
                ];
            }
        );

        $provider = $this->createMock(OpenAiDirectDocumentProvider::class);
        $provider->expects($this->never())->method('extractDocumentsWithPreliminaryAnalysis');

        $service = new DirectAiAnalysisService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AnalysisRepository::class),
            $mongoRepo,
            $this->createMock(OcrCacheRepository::class),
            $provider,
            $storageResolver,
            $this->createMock(LoggerInterface::class),
            $this->createMock(MessageBusInterface::class),
            $textExtractor,
            new AnswerSheetExtractor(new \Psr\Log\NullLogger()),
        );

        try {
            $service->runDirectExtraction('test-analysis-id', 'test-student-id');

            $exam = $mongoAnalysis->getExam();
            $answers = $exam->getExtractedAnswers();

            // Every file contributes; nothing is overwritten or deduplicated
            // merely because the question numbers overlap.
            $this->assertCount(9, $answers, 'All answers from both files must be persisted');
            $this->assertSame(9, $exam->getAnswerCount(), 'answerCount must represent the combined collection');

            // Provenance identifies the originating file for every answer.
            $byFile = [];
            foreach ($answers as $answer) {
                $this->assertArrayHasKey('fileId', $answer['source']);
                $this->assertArrayHasKey('filename', $answer['source']);
                $byFile[$answer['source']['fileId']][] = $answer['questionNumber'];
            }

            $this->assertCount(6, $byFile['file-1'] ?? [], 'First file must contribute its 6 answers');
            $this->assertCount(3, $byFile['file-2'] ?? [], 'Second file must contribute its 3 answers');
            $this->assertSame(
                'sample_answer_sheet_6_questions.pdf',
                $answers[0]['source']['filename']
            );
            $this->assertSame(1, $answers[0]['source']['page']);
            $this->assertSame(2, $answers[6]['source']['page']);

            // Colliding question numbers are preserved on both sides.
            $this->assertContains('1', $byFile['file-1']);
            $this->assertContains('1', $byFile['file-2']);

            $this->assertSame('SUCCESS', $exam->getExtractionResult());
            $this->assertGreaterThan(0.0, $exam->getExtractionConfidence());

            $processing = $mongoAnalysis->getProcessing();
            $this->assertSame('READY_FOR_REVIEW', $processing->getStage());
            $this->assertSame('NEEDS_REVIEW', $processing->getStatus());
        } finally {
            foreach (array_keys($texts) as $fileId) {
                @unlink(sys_get_temp_dir() . '/' . $fileId . '.pdf');
            }
        }
    }

    public function testRunDirectExtractionReportsPartialWhenOneFileFails(): void
    {
        $mongoAnalysis = $this->createAnalysis('DRAFT');
        $mongoAnalysis->getDocuments()->addAnswerSheet(new \App\Document\DocumentFile());

        $answerSheets = $mongoAnalysis->getDocuments()->getAnswerSheets();
        foreach ($answerSheets as $index => $answerSheet) {
            $answerSheet->setStatus('UPLOADED');
            $answerSheet->setFileId('file-' . ($index + 1));
            $answerSheet->setMimeType('application/pdf');
            $answerSheet->setPage($index + 1);
        }

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $storageResolver = $this->createMock(AnalysisStorageResolver::class);
        $storageResolver->method('getFilePathByFileId')->willReturnCallback(
            static fn(string $analysisId, string $fileId): string => sys_get_temp_dir() . '/' . $fileId . '.pdf'
        );

        $goodText = $this->answerSheetText(2);
        file_put_contents(sys_get_temp_dir() . '/file-1.pdf', $goodText);

        $textExtractor = $this->createMock(DocumentTextExtractorInterface::class);
        $textExtractor->method('supportsMimeType')->willReturn(true);
        $textExtractor->method('extract')->willReturnCallback(
            static function (string $filePath) use ($goodText): array {
                if (str_ends_with($filePath, 'file-2.pdf')) {
                    throw new \RuntimeException('corrupt file');
                }

                return [
                    'text' => $goodText,
                    'pages' => [['page' => 1, 'text' => $goodText]],
                    'pageCount' => 1,
                    'hasExtractableText' => true,
                    'extractionMethod' => 'pdf_text',
                ];
            }
        );

        $service = new DirectAiAnalysisService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AnalysisRepository::class),
            $mongoRepo,
            $this->createMock(OcrCacheRepository::class),
            $this->createMock(OpenAiDirectDocumentProvider::class),
            $storageResolver,
            $this->createMock(LoggerInterface::class),
            $this->createMock(MessageBusInterface::class),
            $textExtractor,
            new AnswerSheetExtractor(new \Psr\Log\NullLogger()),
        );

        try {
            $service->runDirectExtraction('test-analysis-id', 'test-student-id');

            $exam = $mongoAnalysis->getExam();
            // The readable file still contributes its answers.
            $this->assertNotEmpty($exam->getExtractedAnswers());
            // SUCCESS must NOT be reported when only one of two files worked.
            $this->assertSame('PARTIAL', $exam->getExtractionResult());
            $this->assertSame('READY_FOR_REVIEW', $mongoAnalysis->getProcessing()->getStage());
        } finally {
            @unlink(sys_get_temp_dir() . '/file-1.pdf');
        }
    }

    /**
     * Regression for the real-world bug: file 1 is uploaded, preparation runs
     * and moves the analysis to NEEDS_REVIEW, then file 2 is uploaded.
     *
     * The second prepare message used to be discarded by the "already
     * prepared" guard, so file 2 was never extracted and the persisted
     * answers only ever referenced file 1.
     */
    public function testRunDirectExtractionRepreparesWhenNewAnswerSheetUploadedAfterFirstPreparation(): void
    {
        $mongoAnalysis = $this->createAnalysis('NEEDS_REVIEW');

        // Only the FIRST file was prepared so far.
        $first = $mongoAnalysis->getDocuments()->getAnswerSheet();
        $first->setStatus('UPLOADED');
        $first->setFileId('file-1');
        $first->setOriginalFilename('english_answer_sheet.pdf');
        $first->setMimeType('application/pdf');
        $first->setPage(1);

        // The user then uploads a SECOND file.
        $mongoAnalysis->getDocuments()->addAnswerSheet(new \App\Document\DocumentFile());
        $second = $mongoAnalysis->getDocuments()->getAnswerSheets()[1];
        $second->setStatus('UPLOADED');
        $second->setFileId('file-2');
        $second->setOriginalFilename('sample_answer_sheet_6_questions.pdf');
        $second->setMimeType('application/pdf');
        $second->setPage(1);

        // Stale extraction result that only covers file 1.
        $mongoAnalysis->getExam()->setExtractedAnswers([
            [
                'questionNumber' => '1',
                'answerText' => 'stale answer',
                'source' => [
                    'fileId' => 'file-1',
                    'filename' => 'english_answer_sheet.pdf',
                    'page' => 1,
                    'bbox' => null,
                ],
            ],
        ]);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $storageResolver = $this->createMock(AnalysisStorageResolver::class);
        $storageResolver->method('getFilePathByFileId')->willReturnCallback(
            static fn(string $analysisId, string $fileId): string => '/permanent/' . $fileId . '.pdf'
        );

        $texts = [
            'file-1' => $this->answerSheetText(6),
            'file-2' => $this->answerSheetText(3),
        ];

        $textExtractor = $this->createMock(DocumentTextExtractorInterface::class);
        $textExtractor->method('supportsMimeType')->willReturn(true);
        $textExtractor->method('extract')->willReturnCallback(
            static function (string $filePath) use ($texts): array {
                $fileId = basename($filePath, '.pdf');

                return [
                    'text' => $texts[$fileId] ?? '',
                    'pages' => [['page' => 1, 'text' => $texts[$fileId] ?? '']],
                    'pageCount' => 1,
                    'hasExtractableText' => true,
                    'extractionMethod' => 'pdf_text',
                ];
            }
        );

        $service = new DirectAiAnalysisService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AnalysisRepository::class),
            $mongoRepo,
            $this->createMock(OcrCacheRepository::class),
            $this->createMock(OpenAiDirectDocumentProvider::class),
            $storageResolver,
            $this->createMock(LoggerInterface::class),
            $this->createMock(MessageBusInterface::class),
            $textExtractor,
            new AnswerSheetExtractor(new \Psr\Log\NullLogger()),
        );

        $service->runDirectExtraction('test-analysis-id', 'test-student-id');

        $answers = $mongoAnalysis->getExam()->getExtractedAnswers();

        // Preparation must re-run and now cover BOTH files.
        $byFile = [];
        foreach ($answers as $answer) {
            $byFile[$answer['source']['fileId']][] = $answer;
        }

        $this->assertArrayHasKey('file-1', $byFile, 'First file must be extracted');
        $this->assertArrayHasKey('file-2', $byFile, 'Newly uploaded second file must be extracted');
        $this->assertCount(6, $byFile['file-1']);
        $this->assertCount(3, $byFile['file-2']);
        $this->assertSame(9, $mongoAnalysis->getExam()->getAnswerCount());

        // The stale single-file answer must be gone.
        foreach ($answers as $answer) {
            $this->assertNotSame('stale answer', $answer['answerText']);
        }
    }

    public function testRunDirectExtractionSkipsWhenAlreadyPreparedForSameFiles(): void
    {
        $mongoAnalysis = $this->createAnalysis('NEEDS_REVIEW');

        $first = $mongoAnalysis->getDocuments()->getAnswerSheet();
        $first->setStatus('UPLOADED');
        $first->setFileId('file-1');
        $first->setMimeType('application/pdf');
        $first->setPage(1);

        // Extraction already covers this exact file.
        $mongoAnalysis->getExam()->setExtractedAnswers([
            [
                'questionNumber' => '1',
                'answerText' => 'already prepared',
                'source' => [
                    'fileId' => 'file-1',
                    'filename' => null,
                    'page' => 1,
                    'bbox' => null,
                ],
            ],
        ]);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $textExtractor = $this->createMock(DocumentTextExtractorInterface::class);
        $textExtractor->expects($this->never())->method('extract');

        $service = new DirectAiAnalysisService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AnalysisRepository::class),
            $mongoRepo,
            $this->createMock(OcrCacheRepository::class),
            $this->createMock(OpenAiDirectDocumentProvider::class),
            $this->createMock(AnalysisStorageResolver::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(MessageBusInterface::class),
            $textExtractor,
            new AnswerSheetExtractor(new \Psr\Log\NullLogger()),
        );

        $service->runDirectExtraction('test-analysis-id', 'test-student-id');

        // No new file -> preparation is skipped and the result untouched.
        $this->assertSame('already prepared', $mongoAnalysis->getExam()->getExtractedAnswers()[0]['answerText']);
    }

    /**
     * Build answer-sheet text containing $count "Question N: ... Answer: ..."
     * blocks, which is what the regex extractor consumes.
     */
    private function answerSheetText(int $count): string
    {
        $text = "Student Name: Jane Doe\nRoll Number: 21\nSubject: Mathematics\nTotal Marks: 40\n\n";
        for ($i = 1; $i <= $count; $i++) {
            $text .= "Question {$i}: Sample question {$i}\nAnswer: Sample answer {$i}\n\n";
        }

        return $text;
    }

    /**
     * End-to-end orchestration test: complete successful Direct AI path from
     * runDirectAnalysis() through final persistence/completion.
     * Mocks ONLY the external AI/network boundary.
     * Verifies the complete successful orchestration path executes correctly.
     */
    public function testRunDirectAnalysisEndToEndSuccessfulOrchestration(): void
    {
        // Create analysis in NEEDS_REVIEW status (ready for AI analysis)
        $mongoAnalysis = $this->createAnalysis('NEEDS_REVIEW');
        
        // Add uploaded answer sheet
        $mongoAnalysis->getDocuments()->addAnswerSheet(new \App\Document\DocumentFile());
        $answerSheet = $mongoAnalysis->getDocuments()->getAnswerSheet();
        $answerSheet->setStatus('UPLOADED');
        $answerSheet->setFileId('file-1');
        $answerSheet->setOriginalFilename('answer-sheet.pdf');
        $answerSheet->setMimeType('application/pdf');
        $answerSheet->setPage(1);

        // Question paper is optional but let's include one
        $mongoAnalysis->getDocuments()->getQuestionPaper()->setStatus('UPLOADED');
        $mongoAnalysis->getDocuments()->getQuestionPaper()->setFileId('qp-file');
        $mongoAnalysis->getDocuments()->getQuestionPaper()->setOriginalFilename('question-paper.pdf');
        $mongoAnalysis->getDocuments()->getQuestionPaper()->setMimeType('application/pdf');
        $mongoAnalysis->getDocuments()->getQuestionPaper()->setPage(1);

        // Persist file hash
        $mongoAnalysis->setAnswerSheetFileHash('test-hash-123');

        // Mock MongoDB repository
        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        // Mock storage resolver
        $storageResolver = $this->createMock(AnalysisStorageResolver::class);
        $storageResolver->method('getFilePathByFileId')->willReturnCallback(
            static fn(string $analysisId, string $fileId): string => '/tmp/' . $fileId . '.pdf'
        );

        // Mock DocumentTextExtractor (SetaPDF) - returns extracted text from PDF
        $textExtractor = $this->createMock(DocumentTextExtractorInterface::class);
        $textExtractor->method('supportsMimeType')->willReturn(true);
        $extractedPages = [];
        for ($i = 1; $i <= 6; $i++) {
            $extractedPages[] = [
                'page' => $i,
                'text' => "Question {$i}: Sample question {$i}\nAnswer: Sample answer {$i}\n\n",
            ];
        }
        $textExtractor->method('extract')->willReturn([
            'text' => implode("\n", array_column($extractedPages, 'text')),
            'pages' => $extractedPages,
            'pageCount' => 6,
            'hasExtractableText' => true,
            'extractionMethod' => 'pdf_text',
        ]);

        // Mock OpenAiDirectDocumentProvider - this is the ONLY external boundary we mock
        // It simulates a successful Direct AI response with all required fields
        $mockAiResponse = [
            'extractedAnswers' => [
                ['questionNumber' => '1', 'answerText' => 'Answer 1', 'page' => 1, 'confidence' => 0.9, 'sourcePage' => '1'],
                ['questionNumber' => '2', 'answerText' => 'Answer 2', 'page' => 1, 'confidence' => 0.85, 'sourcePage' => '1'],
                ['questionNumber' => '3', 'answerText' => 'Answer 3', 'page' => 2, 'confidence' => 0.9, 'sourcePage' => '2'],
                ['questionNumber' => '4', 'answerText' => 'Answer 4', 'page' => 2, 'confidence' => 0.8, 'sourcePage' => '2'],
                ['questionNumber' => '5', 'answerText' => 'Answer 5', 'page' => 3, 'confidence' => 0.85, 'sourcePage' => '3'],
                ['questionNumber' => '6', 'answerText' => 'Answer 6', 'page' => 3, 'confidence' => 0.9, 'sourcePage' => '3'],
            ],
            'extractedQuestions' => [
                ['questionNumber' => '1', 'questionText' => 'Question 1', 'marks' => 5, 'confidence' => 0.9],
                ['questionNumber' => '2', 'questionText' => 'Question 2', 'marks' => 5, 'confidence' => 0.85],
                ['questionNumber' => '3', 'questionText' => 'Question 3', 'marks' => 5, 'confidence' => 0.9],
                ['questionNumber' => '4', 'questionText' => 'Question 4', 'marks' => 5, 'confidence' => 0.85],
                ['questionNumber' => '5', 'questionText' => 'Question 5', 'marks' => 5, 'confidence' => 0.85],
                ['questionNumber' => '6', 'questionText' => 'Question 6', 'marks' => 5, 'confidence' => 0.9],
            ],
            'extractedMetadata' => [
                'studentName' => 'Jane Doe',
                'rollNumber' => '21',
                'subject' => 'Mathematics',
                'examTitle' => 'Mid Term Exam',
                'class' => '10',
                'section' => 'A',
                'totalMarks' => 30,
                'scoredMarks' => 25,
            ],
            'extraction' => [
                'status' => 'SUCCESS',
                'confidence' => 0.9,
            ],
            'questionAnalysis' => [
                [
                    'questionNumber' => '1',
                    'status' => 'COMPLETE',
                    'question' => 'Question 1',
                    'studentAnswer' => 'Answer 1',
                    'evaluation' => [
                        'evaluationStatus' => 'EVALUATED',
                        'correctness' => 'CORRECT',
                        'marksAwarded' => 5,
                        'maxMarks' => 5,
                        'whatStudentDidWell' => 'Correct answer',
                        'mistake' => '',
                        'whyItIsWrong' => '',
                        'missingPoints' => [],
                    ],
                    'concept' => [
                        'name' => 'Algebra',
                        'status' => 'STRONG',
                        'explanation' => 'Student demonstrated strong algebraic skills',
                    ],
                    'improvement' => [
                        'whatToLearn' => '',
                        'whatToPractice' => '',
                        'recommendedAction' => 'Continue practicing',
                    ],
                    'evidence' => [
                        ['type' => 'answer', 'questionNumber' => '1', 'reason' => 'Correct algebraic manipulation'],
                    ],
                ],
                [
                    'questionNumber' => '2',
                    'status' => 'COMPLETE',
                    'question' => 'Question 2',
                    'studentAnswer' => 'Answer 2',
                    'evaluation' => [
                        'evaluationStatus' => 'EVALUATED',
                        'correctness' => 'PARTIALLY_CORRECT',
                        'marksAwarded' => 3,
                        'maxMarks' => 5,
                        'whatStudentDidWell' => 'Correct method',
                        'mistake' => 'Minor arithmetic error',
                        'whyItIsWrong' => 'Calculation error in final step',
                        'missingPoints' => ['Correct final calculation'],
                    ],
                    'concept' => [
                        'name' => 'Geometry',
                        'status' => 'DEVELOPING',
                        'explanation' => 'Correct method but arithmetic error',
                    ],
                    'improvement' => [
                        'whatToLearn' => 'Double-check arithmetic',
                        'whatToPractice' => 'Practice similar problems',
                        'recommendedAction' => 'Review arithmetic operations',
                    ],
                    'evidence' => [
                        ['type' => 'answer', 'questionNumber' => '2', 'reason' => 'Method correct, arithmetic error'],
                    ],
                ],
                [
                    'questionNumber' => '3',
                    'status' => 'COMPLETE',
                    'question' => 'Question 3',
                    'studentAnswer' => 'Answer 3',
                    'evaluation' => [
                        'evaluationStatus' => 'EVALUATED',
                        'correctness' => 'CORRECT',
                        'marksAwarded' => 5,
                        'maxMarks' => 5,
                        'whatStudentDidWell' => 'Perfect',
                        'mistake' => '',
                        'whyItIsWrong' => '',
                        'missingPoints' => [],
                    ],
                    'concept' => [
                        'name' => 'Statistics',
                        'status' => 'STRONG',
                        'explanation' => 'Perfect',
                    ],
                    'improvement' => [
                        'whatToLearn' => '',
                        'whatToPractice' => '',
                        'recommendedAction' => 'Continue',
                    ],
                    'evidence' => [
                        ['type' => 'answer', 'questionNumber' => '3', 'reason' => 'Perfect answer'],
                    ],
                ],
                [
                    'questionNumber' => '4',
                    'status' => 'COMPLETE',
                    'question' => 'Question 4',
                    'studentAnswer' => 'Answer 4',
                    'evaluation' => [
                        'evaluationStatus' => 'EVALUATED',
                        'correctness' => 'PARTIALLY_CORRECT',
                        'marksAwarded' => 3,
                        'maxMarks' => 5,
                        'whatStudentDidWell' => 'Correct approach',
                        'mistake' => 'Minor error',
                        'whyItIsWrong' => 'Rounding error',
                        'missingPoints' => ['Precise calculation'],
                    ],
                    'concept' => [
                        'name' => 'Trigonometry',
                        'status' => 'DEVELOPING',
                        'explanation' => 'Good understanding but calculation error',
                    ],
                    'improvement' => [
                        'whatToLearn' => 'Trigonometric identities',
                        'whatToPractice' => 'Practice more',
                        'recommendedAction' => 'Review',
                    ],
                    'evidence' => [
                        ['type' => 'answer', 'questionNumber' => '4', 'reason' => 'Formula correct, calculation error'],
                    ],
                ],
                [
                    'questionNumber' => '5',
                    'status' => 'COMPLETE',
                    'question' => 'Question 5',
                    'studentAnswer' => 'Answer 5',
                    'evaluation' => [
                        'evaluationStatus' => 'EVALUATED',
                        'correctness' => 'CORRECT',
                        'marksAwarded' => 5,
                        'maxMarks' => 5,
                        'whatStudentDidWell' => 'Excellent',
                        'mistake' => '',
                        'whyItIsWrong' => '',
                        'missingPoints' => [],
                    ],
                    'concept' => [
                        'name' => 'Calculus',
                        'status' => 'STRONG',
                        'explanation' => 'Excellent',
                    ],
                    'improvement' => [
                        'whatToLearn' => '',
                        'whatToPractice' => '',
                        'recommendedAction' => 'Continue',
                    ],
                    'evidence' => [
                        ['type' => 'answer', 'questionNumber' => '5', 'reason' => 'Perfect calculus'],
                    ],
                ],
                [
                    'questionNumber' => '6',
                    'status' => 'COMPLETE',
                    'question' => 'Question 6',
                    'studentAnswer' => 'Answer 6',
                    'evaluation' => [
                        'evaluationStatus' => 'EVALUATED',
                        'correctness' => 'CORRECT',
                        'marksAwarded' => 5,
                        'maxMarks' => 5,
                        'whatStudentDidWell' => 'Perfect',
                        'mistake' => '',
                        'whyItIsWrong' => '',
                        'missingPoints' => [],
                    ],
                    'concept' => [
                        'name' => 'Probability',
                        'status' => 'STRONG',
                        'explanation' => 'Perfect',
                    ],
                    'improvement' => [
                        'whatToLearn' => '',
                        'whatToPractice' => '',
                        'recommendedAction' => 'Continue',
                    ],
                    'evidence' => [
                        ['type' => 'answer', 'questionNumber' => '6', 'reason' => 'Perfect probability'],
                    ],
                ],
            ],
            'currentExamAnalysis' => [
                'performance' => [
                    'score' => 28,
                    'totalMarks' => 30,
                    'percentage' => 93.33,
                ],
                'strengths' => ['Algebra', 'Statistics', 'Calculus'],
                'weaknesses' => ['Trigonometry', 'Geometry'],
                'needsAttention' => ['Trigonometry'],
                'conceptGaps' => ['Trigonometry'],
                'recommendations' => ['Practice trigonometry problems'],
                'conceptAssessments' => [
                    ['concept' => 'Algebra', 'status' => 'STRONG', 'confidence' => 0.95, 'evidence' => []],
                    ['concept' => 'Statistics', 'status' => 'STRONG', 'confidence' => 0.9, 'evidence' => []],
                    ['concept' => 'Calculus', 'status' => 'STRONG', 'confidence' => 0.85, 'evidence' => []],
                    ['concept' => 'Trigonometry', 'status' => 'DEVELOPING', 'confidence' => 0.5, 'evidence' => []],
                    ['concept' => 'Geometry', 'status' => 'NEEDS_ATTENTION', 'confidence' => 0.4, 'evidence' => []],
                ],
                'mistakes' => [],
                'evidence' => [],
                'strengths' => ['Algebra', 'Statistics', 'Calculus'],
                'weaknesses' => ['Trigonometry', 'Geometry'],
                'needsAttention' => ['Trigonometry'],
                'conceptGaps' => ['Trigonometry'],
                'recommendations' => ['Practice trigonometry problems'],
            ],
        ];

        // Mock the provider to return our canned AI response
        $provider = $this->createMock(OpenAiDirectDocumentProvider::class);
        $provider->method('extractDocumentsWithPreliminaryAnalysis')
            ->willReturn($this->createMockProviderResponse($mockAiResponse));

        // Mock storage resolver
        $storageResolver = $this->createMock(AnalysisStorageResolver::class);
        $storageResolver->method('getFilePathByFileId')
            ->willReturnCallback(
                static fn(string $analysisId, string $fileId): string => '/tmp/' . $fileId . '.pdf'
            );

        // Create analysis
        $mongoAnalysis = $this->createAnalysis('NEEDS_REVIEW');
        $mongoAnalysis->getDocuments()->addAnswerSheet(new \App\Document\DocumentFile());
        $answerSheet = $mongoAnalysis->getDocuments()->getAnswerSheet();
        $answerSheet->setStatus('UPLOADED');
        $answerSheet->setFileId('file-1');
        $answerSheet->setOriginalFilename('answer-sheet.pdf');
        $answerSheet->setMimeType('application/pdf');
        $answerSheet->setPage(1);

        $mongoAnalysis->getDocuments()->getQuestionPaper()->setStatus('UPLOADED');
        $mongoAnalysis->getDocuments()->getQuestionPaper()->setFileId('qp-file');
        $mongoAnalysis->getDocuments()->getQuestionPaper()->setOriginalFilename('question-paper.pdf');
        $mongoAnalysis->getDocuments()->getQuestionPaper()->setMimeType('application/pdf');
        $mongoAnalysis->setAnswerSheetFileHash('test-hash-123');

        // Mock repositories
        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $storageResolver = $this->createMock(AnalysisStorageResolver::class);
        $storageResolver->method('getFilePathByFileId')
            ->willReturnCallback(
                static fn(string $analysisId, string $fileId): string => '/tmp/' . $fileId . '.pdf'
            );

        // Create service with mocked dependencies
        $provider = $this->createMock(OpenAiDirectDocumentProvider::class);
        $provider->method('extractDocumentsWithPreliminaryAnalysis')
            ->willReturn($this->createMockProviderResponse());

        $storageResolver = $this->createMock(AnalysisStorageResolver::class);
        $storageResolver->method('getFilePathByFileId')
            ->willReturnCallback(
                static fn(string $analysisId, string $fileId): string => '/tmp/' . $fileId . '.pdf'
            );

        $provider = $this->createMock(OpenAiDirectDocumentProvider::class);
        $provider->method('extractDocumentsWithPreliminaryAnalysis')
            ->willReturn($this->createMockProviderResponse());

        $storageResolver = $this->createMock(AnalysisStorageResolver::class);
        $storageResolver->method('getFilePathByFileId')
            ->willReturnCallback(
                static fn(string $analysisId, string $fileId): string => '/tmp/' . $fileId . '.pdf'
            );

        $service = $this->createService(
            $this->createMock(MongoAnalysisRepository::class),
            $provider,
            $storageResolver,
            null, // pgRepo not needed for this test
            null, // textExtractor - we mock the provider directly
            null, // answerSheetExtractor - not needed when mocking provider
        );

        // Execute the full orchestration
        $service->runDirectAnalysis('test-analysis-id', 'test-student-id', false, 0);

        // Verify the analysis was completed successfully
        $mongoAnalysis = $this->mongoAnalysisRepository->findByAnalysisIdAndStudentId('test-analysis-id', 'test-student-id');
        
        // Verify AI result is persisted
        $this->assertSame('DONE', $mongoAnalysis->getStatus());
        $this->assertSame('DONE', $mongoAnalysis->getProcessing()->getStatus());
        $this->assertSame('COMPLETED', $mongoAnalysis->getProcessing()->getStage());

        // Verify AI analysis is persisted
        $aiAnalysis = $mongoAnalysis->getAiAnalysis();
        $this->assertNotNull($aiAnalysis);
        $this->assertNotNull($aiAnalysis['currentExamAnalysis']);
        $this->assertNotNull($aiAnalysis['questionAnalysis']);
        $this->assertCount(6, $aiAnalysis['questionAnalysis']);
        $this->assertArrayHasKey('currentExamAnalysis', $aiAnalysis);

        // Verify extracted data is persisted
        $exam = $mongoAnalysis->getExam();
        $this->assertCount(6, $exam->getExtractedQuestions());
        $this->assertCount(6, $exam->getExtractedAnswers());
        $this->assertNotEmpty($exam->getExtractedMetadata());
        $this->assertSame('SUCCESS', $exam->getExtractionResult());
        $this->assertSame('OPENAI_DIRECT_DOCUMENT', $exam->getExtractionMethod());
    }

    public function testRunDirectAnalysisSucceedsWhenConfirmedMetadataExistsButExtractedMetadataIsEmpty(): void
    {
        $analysisId = 'test-analysis-id';
        $studentId = 'test-student-id';

        $mongoAnalysis = $this->createAnalysis('NEEDS_REVIEW');
        $mongoAnalysis->getDocuments()->addAnswerSheet(new \App\Document\DocumentFile());
        $answerSheet = $mongoAnalysis->getDocuments()->getAnswerSheet();
        $answerSheet->setStatus('UPLOADED');
        $answerSheet->setFileId('file-1');
        $answerSheet->setOriginalFilename('answer-sheet.pdf');
        $answerSheet->setMimeType('application/pdf');
        $answerSheet->setPage(1);

        $mongoAnalysis->getDocuments()->getQuestionPaper()->setStatus('NOT_UPLOADED');
        $mongoAnalysis->setMetadataConfirmationStatus('CONFIRMED');
        $mongoAnalysis->getExam()->setExtractedMetadata([]);
        $mongoAnalysis->setAnswerSheetFileHash('precomputed-hash');

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $pgAnalysis = $this->createMock(\App\Entity\Analysis::class);
        $pgAnalysis->method('getMetadataConfirmationStatus')->willReturn('CONFIRMED');
        $pgAnalysis->method('getSubjectName')->willReturn('Mathematics');
        $pgAnalysis->method('getTotalMarks')->willReturn(30);

        $pgRepo = $this->createMock(AnalysisRepository::class);
        $pgRepo->method('findByAnalysisId')->willReturn($pgAnalysis);

        $storageResolver = $this->createMock(AnalysisStorageResolver::class);
        $storageResolver->method('getFilePathByFileId')
            ->willReturnCallback(static fn(string $analysisId, string $fileId): string => '/tmp/' . $fileId . '.pdf');

        $mockResult = new \App\DTO\Analysis\Direct\DirectExtractionResult(
            metadata: new \App\DTO\Analysis\ExtractedAnswerSheetMetadata(),
            extractedAnswers: [],
            extractedQuestions: [],
            confidence: 0.0,
            extractionMethod: 'OPENAI_DIRECT_DOCUMENT',
            analysis: new \App\DTO\Analysis\Direct\CurrentExamAnalysis(
                performance: new \App\DTO\Analysis\Direct\Performance(score: 0, totalMarks: 30, percentage: 0.0),
                strengths: [],
                weaknesses: [],
                needsAttention: [],
                conceptGaps: [],
                recommendations: [],
                conceptAssessments: [],
                mistakes: [],
                evidence: [],
            ),
            questionAnalysis: [],
        );

        $provider = $this->createMock(OpenAiDirectDocumentProvider::class);
        $provider->method('extractDocumentsWithPreliminaryAnalysis')->willReturn($mockResult);

        $service = $this->createService(
            $mongoRepo,
            $provider,
            $storageResolver,
            $pgRepo,
            null,
            null
        );

        $service->runDirectAnalysis($analysisId, $studentId, false, 0);

        $mongoAnalysis = $mongoRepo->findByAnalysisIdAndStudentId($analysisId, $studentId);
        $this->assertSame('DONE', $mongoAnalysis->getStatus());
        $this->assertSame('DONE', $mongoAnalysis->getProcessing()->getStatus());
    }

    public function testRunDirectAnalysisFailsWhenConfirmedMetadataIsMissing(): void
    {
        $analysisId = 'test-analysis-id';
        $studentId = 'test-student-id';

        $mongoAnalysis = $this->createAnalysis('NEEDS_REVIEW');
        $mongoAnalysis->getDocuments()->addAnswerSheet(new \App\Document\DocumentFile());
        $answerSheet = $mongoAnalysis->getDocuments()->getAnswerSheet();
        $answerSheet->setStatus('UPLOADED');
        $answerSheet->setFileId('file-1');
        $answerSheet->setOriginalFilename('answer-sheet.pdf');
        $answerSheet->setMimeType('application/pdf');
        $answerSheet->setPage(1);

        $mongoAnalysis->getDocuments()->getQuestionPaper()->setStatus('NOT_UPLOADED');
        $mongoAnalysis->setMetadataConfirmationStatus(null);
        $mongoAnalysis->getExam()->setExtractedMetadata([]);

        $mongoRepo = $this->createMock(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($mongoAnalysis);

        $pgRepo = $this->createMock(AnalysisRepository::class);
        $pgRepo->method('findByAnalysisId')->willReturn(null);

        $storageResolver = $this->createMock(AnalysisStorageResolver::class);
        $storageResolver->method('getFilePathByFileId')
            ->willReturnCallback(static fn(string $analysisId, string $fileId): string => '/tmp/' . $fileId . '.pdf');

        $service = $this->createService(
            $mongoRepo,
            null,
            $storageResolver,
            $pgRepo,
            null,
            null
        );

        $this->expectException(\App\Exception\AnalysisLifecycleException::class);
        $this->expectExceptionMessage('Confirmed metadata is required before analysis.');
        $service->runDirectAnalysis($analysisId, $studentId, false, 0);
    }
}
