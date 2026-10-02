<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis\Processing;

use App\Document\Analysis;
use App\Document\DocumentFile;
use App\Document\Documents;
use App\Document\Exam;
use App\Document\Processing;
use App\DTO\Analysis\DocumentClassificationResult;
use App\DTO\Analysis\ExtractedAnswerSheetMetadata;
use App\Enum\Analysis\DocumentType;
use App\Enum\Analysis\TextSufficiencyDecision;
use App\Exception\PdfRendererUnavailableException;
use App\Exception\VisionApiException;
use App\Repository\AnalysisRepository;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use App\Repository\MongoDB\OcrCacheRepository;
use App\Service\Analysis\AnalysisProcessingService;
use App\Service\Analysis\AnalysisStorageResolver;
use App\Service\Analysis\Processing\AnswerSheetDocumentClassifier;
use App\Service\Analysis\Processing\AnswerSheetExtractor;
use App\Service\Analysis\Processing\DocumentTextExtractorInterface;
use App\Service\Analysis\Processing\ExtractionQualityValidator;
use App\Service\Analysis\Processing\MeaningfulTextDetector;
use App\Service\Analysis\Processing\OpenAiVisionProvider;
use App\Service\Analysis\Processing\PdfPageRendererInterface;
use App\Service\Analysis\Processing\QuestionExtractor;
use App\Service\Analysis\Processing\VisionOCRServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

class AnalysisProcessingServiceOcrTest extends TestCase
{
    private function createAnalysisDocument(string $fileId = 'file-123'): Analysis
    {
        $mongoAnalysis = new Analysis();

        $mongoAnalysis->setAnalysisId($mongoAnalysis->getId());
        $mongoAnalysis->setStudentId('student-123');
        $mongoAnalysis->setSource('EXTERNAL_UPLOAD');
        $mongoAnalysis->setStatus('DRAFT');

        $documents = new Documents();
        $answerSheet = new DocumentFile();
        $answerSheet->setFileId($fileId);
        $answerSheet->setStatus('UPLOADED');
        $documents->setAnswerSheet($answerSheet);

        $questionPaper = new DocumentFile();
        $documents->setQuestionPaper($questionPaper);

        $mongoAnalysis->setDocuments($documents);
        $mongoAnalysis->setExam(new Exam());
        $mongoAnalysis->setProcessing(new Processing());

        return $mongoAnalysis;
    }

    private function createTempPdf(): string
    {
        $path = sys_get_temp_dir() . '/test_ocr_analysis_' . uniqid() . '.pdf';
        file_put_contents($path, '%PDF-1.4 fake content');
        return $path;
    }

    public function testTypedPdfExtractionUsableDoesNotInvokeRenderer(): void
    {
        $pdfPath = $this->createTempPdf();

        try {
            $textExtractor = $this->createMock(DocumentTextExtractorInterface::class);
            $textExtractor->method('extract')->willReturn([
                'text' => 'Meaningful answer sheet text with answers and detailed working for multiple questions',
                'pages' => [['page' => 1, 'text' => 'Question 1 Answer 1 with detailed working and explanation']],
                'pageCount' => 1,
                'hasExtractableText' => true,
                'extractionMethod' => 'pdf_text',
            ]);

            $questionExtractor = $this->createMock(QuestionExtractor::class);

            $answerSheetExtractor = $this->createMock(AnswerSheetExtractor::class);
            $answerSheetExtractor->method('extractFromAnswerSheet')->willReturn([
                'metadata' => new \App\DTO\Analysis\ExtractedAnswerSheetMetadata(),
                'answers' => [new \App\DTO\Analysis\ExtractedAnswer('1', 'Real answer text', 1, 0.9)],
                'extractionResult' => 'SUCCESS',
                'extractionConfidence' => 0.9,
            ]);
            $answerSheetExtractor->method('countMetadataFields')->willReturn(4);

            $qualityValidator = $this->createMock(ExtractionQualityValidator::class);
            $qualityValidator->method('validate')->willReturn([
                'usable' => true,
                'reason' => 'Found 1 meaningful answer(s)',
                'meaningfulAnswerCount' => 1,
                'meaningfulQuestionCount' => 1,
                'metadataCount' => 4,
                'pageAnalysis' => [['page' => 1, 'hasMeaningfulContent' => true, 'textLength' => 30, 'answerCount' => 1, 'placeholderCount' => 0]],
            ]);

            $meaningfulTextDetector = $this->createMock(MeaningfulTextDetector::class);
            $meaningfulTextDetector->method('analyze')->willReturn([
                'decision' => \App\Enum\Analysis\TextSufficiencyDecision::TextSufficient,
                'reason' => 'Text is sufficient',
                'pageAnalysis' => [['page' => 1, 'textLength' => 30, 'meaningfulTextLength' => 30, 'hasMeaningfulContent' => true, 'rejectionReason' => null]],
            ]);

            $documentClassifier = $this->createMock(AnswerSheetDocumentClassifier::class);
            $documentClassifier->method('classifyFromText')->willReturn(
                \App\DTO\Analysis\DocumentClassificationResult::validAnswerSheet(0.9, ['question_numbering', 'answer_content'])
            );
            $documentClassifier->method('classifyFromVision')->willReturn(
                \App\DTO\Analysis\DocumentClassificationResult::validAnswerSheet(0.9, ['question_numbering', 'answer_content'])
            );

            $openAiVision = $this->createMock(OpenAiVisionProvider::class);
            $openAiVision->expects($this->never())->method('processPdf');
            $openAiVision->expects($this->never())->method('processPageImage');

            $visionOcr = $this->createMock(VisionOCRServiceInterface::class);
            $visionOcr->expects($this->never())->method('processPdf');
            $visionOcr->expects($this->never())->method('processPageImage');

            $pdfRenderer = $this->createMock(PdfPageRendererInterface::class);
            $pdfRenderer->expects($this->never())->method('renderPage');
            $pdfRenderer->expects($this->never())->method('isAvailable');

            $mongoRepo = $this->createStub(MongoAnalysisRepository::class);
            $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($this->createAnalysisDocument());

            $storageResolver = $this->createStub(AnalysisStorageResolver::class);
            $storageResolver->method('getFilePathByFileId')->willReturn($pdfPath);

            $service = new AnalysisProcessingService(
                $this->createStub(EntityManagerInterface::class),
                $this->createStub(AnalysisRepository::class),
                $mongoRepo,
                $this->createStub(OcrCacheRepository::class),
                $textExtractor,
                $questionExtractor,
                $answerSheetExtractor,
                $qualityValidator,
                $meaningfulTextDetector,
                $documentClassifier,
                $openAiVision,
                $visionOcr,
                $pdfRenderer,
                new NullLogger(),
                $this->createStub(MessageBusInterface::class),
                $storageResolver
            );

            // Should not throw
            $service->processAnalysis($pdfPath, 'student-123');
        } finally {
            if (file_exists($pdfPath)) {
                unlink($pdfPath);
            }
        }
    }

    public function testScannedPdfWithRendererUnavailableDoesNotCallOcr(): void
    {
        $pdfPath = $this->createTempPdf();

        try {
            $textExtractor = $this->createMock(DocumentTextExtractorInterface::class);
            $textExtractor->method('extract')->willReturn([
                'text' => '',
                'pages' => [['page' => 1, 'text' => '']],
                'pageCount' => 1,
                'hasExtractableText' => false,
                'extractionMethod' => 'pdf_text',
            ]);

            $questionExtractor = $this->createMock(QuestionExtractor::class);

            $answerSheetExtractor = $this->createMock(AnswerSheetExtractor::class);
            $answerSheetExtractor->method('extractFromAnswerSheet')->willReturn([
                'metadata' => new \App\DTO\Analysis\ExtractedAnswerSheetMetadata(),
                'answers' => [],
                'extractionResult' => 'OCR_REQUIRED',
                'extractionConfidence' => 0.0,
            ]);
            $answerSheetExtractor->method('countMetadataFields')->willReturn(0);

            $qualityValidator = $this->createMock(ExtractionQualityValidator::class);
            $qualityValidator->method('validate')->willReturn([
                'usable' => false,
                'reason' => 'No meaningful content',
                'meaningfulAnswerCount' => 0,
                'meaningfulQuestionCount' => 0,
                'metadataCount' => 0,
                'pageAnalysis' => [['page' => 1, 'hasMeaningfulContent' => false, 'textLength' => 0, 'answerCount' => 0, 'placeholderCount' => 0]],
            ]);

            $meaningfulTextDetector = $this->createMock(MeaningfulTextDetector::class);
            $meaningfulTextDetector->method('analyze')->willReturn([
                'decision' => \App\Enum\Analysis\TextSufficiencyDecision::VisualExtractionRequired,
                'reason' => 'No meaningful content',
                'pageAnalysis' => [['page' => 1, 'textLength' => 0, 'meaningfulTextLength' => 0, 'hasMeaningfulContent' => false, 'rejectionReason' => 'Empty text']],
            ]);

            $documentClassifier = $this->createMock(AnswerSheetDocumentClassifier::class);
            $documentClassifier->method('classifyFromText')->willReturn(
                \App\DTO\Analysis\DocumentClassificationResult::validAnswerSheet(0.9, ['question_numbering', 'answer_content'])
            );
            $documentClassifier->method('classifyFromVision')->willReturn(
                \App\DTO\Analysis\DocumentClassificationResult::validAnswerSheet(0.9, ['question_numbering', 'answer_content'])
            );

            $openAiVision = $this->createMock(OpenAiVisionProvider::class);
            // Classification phase calls processPdf for PDF - mock to return valid result
            $openAiVision->method('processPdf')->willReturn([
                'pages' => [['pageNumber' => 1, 'text' => '', 'questions' => [], 'metadata' => [], 'confidence' => 0.0, 'fromCache' => false]],
                'metadata' => ['studentName' => null, 'rollNumber' => null, 'subject' => null, 'examTitle' => null, 'class' => null, 'section' => null, 'totalMarks' => null],
                'confidence' => 0.0,
                'extractionMethod' => 'OPENAI_VISION',
                'ocrMetrics' => ['pagesDetected' => 1, 'pagesProcessed' => 1, 'pagesSkipped' => 0, 'ocrPagesProcessed' => 1, 'ocrCacheHits' => 0, 'ocrAttempts' => 1, 'ocrRetryCount' => 0],
                'language' => 'en',
            ]);
            $openAiVision->expects($this->never())->method('processPageImage');

            $visionOcr = $this->createMock(VisionOCRServiceInterface::class);
            $visionOcr->expects($this->never())->method('processPdf');
            $visionOcr->expects($this->never())->method('processPageImage');

            $pdfRenderer = $this->createMock(PdfPageRendererInterface::class);
            $pdfRenderer->method('isAvailable')->willReturn(false);

            $mongoRepo = $this->createStub(MongoAnalysisRepository::class);
            $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($this->createAnalysisDocument());

            $storageResolver = $this::createStub(AnalysisStorageResolver::class);
            $storageResolver->method('getFilePathByFileId')->willReturn($pdfPath);

            $service = new AnalysisProcessingService(
                $this->createStub(EntityManagerInterface::class),
                $this->createStub(AnalysisRepository::class),
                $mongoRepo,
                $this->createStub(OcrCacheRepository::class),
                $textExtractor,
                $questionExtractor,
                $answerSheetExtractor,
                $qualityValidator,
                $meaningfulTextDetector,
                $documentClassifier,
                $openAiVision,
                $visionOcr,
                $pdfRenderer,
                new NullLogger(),
                $this->createStub(MessageBusInterface::class),
                $storageResolver
            );

            // Should NOT throw - classification succeeds via OpenAI Vision, extraction completes
            $service->processAnalysis($pdfPath, 'student-123');

            // Verify the analysis was marked as FAILED since renderer is unavailable
            // and OpenAI Vision cannot replace the local PDF renderer for OCR
            $analysis = $mongoRepo->findByAnalysisIdAndStudentId($pdfPath, 'student-123');
            $this->assertSame('FAILED', $analysis->getStatus());
        } finally {
            if (file_exists($pdfPath)) {
                unlink($pdfPath);
            }
        }
    }

public function testImageUploadDoesNotInvokeRenderer(): void
    {
        $imagePath = sys_get_temp_dir() . '/test_image_upload_' . uniqid() . '.png';
        file_put_contents($imagePath, 'fake-png');

        try {
            $textExtractor = $this->createMock(DocumentTextExtractorInterface::class);
            $textExtractor->method('extract')->willReturn([
                'text' => '',
                'pages' => [['page' => 1, 'text' => '[Image content - OCR not implemented]']],
                'pageCount' => 1,
                'hasExtractableText' => false,
                'extractionMethod' => 'ocr_not_implemented',
            ]);

            $questionExtractor = $this->createMock(QuestionExtractor::class);

            $answerSheetExtractor = $this->createMock(AnswerSheetExtractor::class);
            $answerSheetExtractor->method('extractFromAnswerSheet')->willReturn([
                'metadata' => new \App\DTO\Analysis\ExtractedAnswerSheetMetadata(),
                'answers' => [new \App\DTO\Analysis\ExtractedAnswer('1', 'Image answer', 1, 0.8)],
                'extractionResult' => 'SUCCESS',
                'extractionConfidence' => 0.8,
            ]);
            $answerSheetExtractor->method('countMetadataFields')->willReturn(2);

            $qualityValidator = $this->createMock(ExtractionQualityValidator::class);
            $qualityValidator->method('validate')->willReturn([
                'usable' => true,
                'reason' => 'Found 1 meaningful answer(s)',
                'meaningfulAnswerCount' => 1,
                'meaningfulQuestionCount' => 1,
                'metadataCount' => 2,
                'pageAnalysis' => [['page' => 1, 'hasMeaningfulContent' => true, 'textLength' => 20, 'answerCount' => 1, 'placeholderCount' => 0]],
            ]);

            $meaningfulTextDetector = $this->createMock(MeaningfulTextDetector::class);
            $meaningfulTextDetector->method('analyze')->willReturn([
                'decision' => \App\Enum\Analysis\TextSufficiencyDecision::VisualExtractionRequired,
                'reason' => 'No meaningful content',
                'pageAnalysis' => [['page' => 1, 'textLength' => 0, 'meaningfulTextLength' => 0, 'hasMeaningfulContent' => false, 'rejectionReason' => 'Empty text']],
            ]);

            $documentClassifier = $this->createMock(AnswerSheetDocumentClassifier::class);
            $documentClassifier->method('classifyFromText')->willReturn(
                \App\DTO\Analysis\DocumentClassificationResult::validAnswerSheet(0.9, ['question_numbering', 'answer_content'])
            );
            $documentClassifier->method('classifyFromVision')->willReturn(
                \App\DTO\Analysis\DocumentClassificationResult::validAnswerSheet(0.9, ['question_numbering', 'answer_content'])
            );

            $openAiVision = $this->createMock(OpenAiVisionProvider::class);
            // Classification phase calls processPageImage for image - mock to return valid result
            $openAiVision->method('processPageImage')->willReturn([
                'pages' => [['pageNumber' => 1, 'text' => 'Answer for question 1 with detailed working', 'questions' => [['id' => '1', 'text' => 'Question 1', 'type' => 'short', 'marks' => 1]], 'metadata' => [], 'confidence' => 0.9, 'fromCache' => false]],
                'metadata' => ['studentName' => null, 'rollNumber' => null, 'subject' => null, 'examTitle' => null, 'class' => null, 'section' => null, 'totalMarks' => null],
                'confidence' => 0.9,
                'extractionMethod' => 'OPENAI_VISION',
                'ocrMetrics' => ['pagesDetected' => 1, 'pagesProcessed' => 1, 'pagesSkipped' => 0, 'ocrPagesProcessed' => 1, 'ocrCacheHits' => 0, 'ocrAttempts' => 1, 'ocrRetryCount' => 0],
                'text' => 'Answer for question 1 with detailed working',
                'questions' => [['id' => '1', 'text' => 'Question 1']],
                'language' => 'en',
            ]);
            $openAiVision->expects($this->never())->method('processPdf');

            $visionOcr = $this->createMock(VisionOCRServiceInterface::class);
            $visionOcr->expects($this->never())->method('processPageImage');
            $visionOcr->expects($this->never())->method('processPdf');

            $pdfRenderer = $this->createMock(PdfPageRendererInterface::class);
            $pdfRenderer->expects($this->never())->method('renderPage');
            $pdfRenderer->expects($this->never())->method('isAvailable');

            $mongoRepo = $this->createStub(MongoAnalysisRepository::class);
            $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($this->createAnalysisDocument());

            $storageResolver = $this->createStub(AnalysisStorageResolver::class);
            $storageResolver->method('getFilePathByFileId')->willReturn($imagePath);

            $service = new AnalysisProcessingService(
                $this->createStub(EntityManagerInterface::class),
                $this->createStub(AnalysisRepository::class),
                $mongoRepo,
                $this->createStub(OcrCacheRepository::class),
                $textExtractor,
                $questionExtractor,
                $answerSheetExtractor,
                $qualityValidator,
                $meaningfulTextDetector,
                $documentClassifier,
                $openAiVision,
                $visionOcr,
                $pdfRenderer,
                new NullLogger(),
                $this->createStub(MessageBusInterface::class),
                $storageResolver
            );

            // Should succeed - OpenAI Vision handles image classification and extraction
            $service->processAnalysis($imagePath, 'student-123');

            // Verify OpenAI Vision was used for image classification/extraction, not Tasknode
            $analysis = $mongoRepo->findByAnalysisIdAndStudentId($imagePath, 'student-123');
            $this->assertSame('NEEDS_REVIEW', $analysis->getStatus());
        } finally {
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
        }
    }

    private function createOcrServiceCommonMocks(string $filePath, bool $isPdf = true): array
    {
        $mongoRepo = $this->createStub(MongoAnalysisRepository::class);
        $mongoRepo->method('findByAnalysisIdAndStudentId')->willReturn($this->createAnalysisDocument());

        $storageResolver = $this->createStub(AnalysisStorageResolver::class);
        $storageResolver->method('getFilePathByFileId')->willReturn($filePath);

        return [
            $mongoRepo,
            $storageResolver,
        ];
    }

    private function getScannedPdfTextExtractorMock(): DocumentTextExtractorInterface
    {
        $textExtractor = $this->createMock(DocumentTextExtractorInterface::class);
        $textExtractor->method('extract')->willReturn([
            'text' => '',
            'pages' => [['page' => 1, 'text' => '']],
            'pageCount' => 1,
            'hasExtractableText' => false,
            'extractionMethod' => 'pdf_text',
        ]);
        return $textExtractor;
    }

    private function getMeaningfulTextDetectorMock(): MeaningfulTextDetector
    {
        $detector = $this->createMock(MeaningfulTextDetector::class);
        $detector->method('analyze')->willReturn([
            'decision' => TextSufficiencyDecision::VisualExtractionRequired,
            'reason' => 'No meaningful content',
            'pageAnalysis' => [['page' => 1, 'textLength' => 0, 'meaningfulTextLength' => 0, 'hasMeaningfulContent' => false, 'rejectionReason' => 'Empty text']],
        ]);
        return $detector;
    }

    private function getAnswerSheetExtractorMock(bool $usable = true): AnswerSheetExtractor
    {
        $extractor = $this->createMock(AnswerSheetExtractor::class);
        $extractor->method('extractFromAnswerSheet')->willReturn([
            'metadata' => new \App\DTO\Analysis\ExtractedAnswerSheetMetadata(),
            'answers' => $usable ? [new \App\DTO\Analysis\ExtractedAnswer('1', 'Answer 1 response', 1, 0.9)] : [],
            'extractionResult' => $usable ? 'SUCCESS' : 'OCR_REQUIRED',
            'extractionConfidence' => $usable ? 0.9 : 0.0,
        ]);
        $extractor->method('countMetadataFields')->willReturn($usable ? 4 : 0);
        return $extractor;
    }

    private function getQualityValidatorMock(bool $usable): ExtractionQualityValidator
    {
        $validator = $this->createMock(ExtractionQualityValidator::class);
        $validator->method('validate')->willReturn([
            'usable' => $usable,
            'reason' => $usable ? 'Found 1 meaningful answer(s)' : 'No meaningful content',
            'meaningfulAnswerCount' => $usable ? 1 : 0,
            'meaningfulQuestionCount' => $usable ? 1 : 0,
            'metadataCount' => $usable ? 4 : 0,
            'pageAnalysis' => [['page' => 1, 'hasMeaningfulContent' => $usable, 'textLength' => 30, 'answerCount' => 1, 'placeholderCount' => 0]],
        ]);
        return $validator;
    }

    private function getDocumentClassifierMock(): AnswerSheetDocumentClassifier
    {
        $classifier = $this->createMock(AnswerSheetDocumentClassifier::class);
        $classifier->method('classifyFromText')->willReturn(
            DocumentClassificationResult::validAnswerSheet(0.9, ['question_numbering', 'answer_content'])
        );
        $classifier->method('classifyFromVision')->willReturn(
            DocumentClassificationResult::validAnswerSheet(0.9, ['question_numbering', 'answer_content'])
        );
        return $classifier;
    }

    private function getOcrMetricsResponse(array $pages, string $text = 'Answer 1 with detailed working and explanation provided for review', array $questions = []): array
    {
        return [
            'pages' => $pages,
            'text' => $text,
            'questions' => $questions,
            'metadata' => ['studentName' => null, 'rollNumber' => null, 'subject' => null, 'examTitle' => null, 'class' => null, 'section' => null, 'totalMarks' => null],
            'confidence' => 0.9,
            'extractionMethod' => 'OPENAI_VISION',
            'ocrMetrics' => [
                'pagesDetected' => 1,
                'pagesProcessed' => 1,
                'pagesSkipped' => 0,
                'pagesAttempted' => 1,
                'pagesFailed' => 0,
                'stoppedReason' => null,
                'ocrPagesProcessed' => 1,
                'ocrCacheHits' => 0,
                'ocrAttempts' => 1,
                'ocrRetryCount' => 0,
            ],
            'language' => 'en',
        ];
    }

    public function testOcrOpenAiVisionSucceedsForPdf(): void
    {
        $pdfPath = $this->createTempPdf();
        [$mongoRepo, $storageResolver] = $this->createOcrServiceCommonMocks($pdfPath);

        $textExtractor = $this->getScannedPdfTextExtractorMock();
        $meaningfulTextDetector = $this->getMeaningfulTextDetectorMock();
        $answerSheetExtractor = $this->getAnswerSheetExtractorMock(true);
        $qualityValidator = $this->getQualityValidatorMock(true);
        $documentClassifier = $this->getDocumentClassifierMock();

        $openAiVision = $this->createMock(OpenAiVisionProvider::class);
        $openAiVision->expects($this->once())->method('processPdf')->willReturn(
            $this->getOcrMetricsResponse([
                ['pageNumber' => 1, 'text' => 'Answer 1 with detailed working and explanation', 'questions' => [], 'metadata' => [], 'confidence' => 0.9, 'fromCache' => false],
            ])
        );

        $visionOcr = $this->createMock(VisionOCRServiceInterface::class);
        $visionOcr->expects($this->never())->method('processPdf');

        $pdfRenderer = $this->createMock(PdfPageRendererInterface::class);
        $pdfRenderer->method('isAvailable')->willReturn(true);

        $questionExtractor = $this->createMock(QuestionExtractor::class);

        $service = new AnalysisProcessingService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AnalysisRepository::class),
            $mongoRepo,
            $this->createStub(OcrCacheRepository::class),
            $textExtractor,
            $questionExtractor,
            $answerSheetExtractor,
            $qualityValidator,
            $meaningfulTextDetector,
            $documentClassifier,
            $openAiVision,
            $visionOcr,
            $pdfRenderer,
            new NullLogger(),
            $this->createStub(MessageBusInterface::class),
            $storageResolver
        );

        try {
            $service->processAnalysis($pdfPath, 'student-123');
            $analysis = $mongoRepo->findByAnalysisIdAndStudentId($pdfPath, 'student-123');
            $this->assertSame('NEEDS_REVIEW', $analysis->getStatus());
        } finally {
            if (file_exists($pdfPath)) {
                unlink($pdfPath);
            }
        }
    }

    public function testOcrOpenAiMinimalReturnsForPdfTasknodeSucceeds(): void
    {
        $pdfPath = $this->createTempPdf();
        [$mongoRepo, $storageResolver] = $this->createOcrServiceCommonMocks($pdfPath);

        $textExtractor = $this->getScannedPdfTextExtractorMock();
        $meaningfulTextDetector = $this->getMeaningfulTextDetectorMock();
        $answerSheetExtractor = $this->getAnswerSheetExtractorMock(true);
        $qualityValidator = $this->getQualityValidatorMock(true);
        $documentClassifier = $this->getDocumentClassifierMock();

        $openAiVision = $this->createMock(OpenAiVisionProvider::class);
        $openAiVision->expects($this->once())->method('processPdf')->willReturn(
            $this->getOcrMetricsResponse([
                ['pageNumber' => 1, 'text' => '', 'questions' => [], 'metadata' => [], 'confidence' => 0.0, 'fromCache' => false],
            ], '', [])
        );

        $visionOcr = $this->createMock(VisionOCRServiceInterface::class);
        $visionOcr->expects($this->once())->method('processPdf')->willReturn(
            $this->getOcrMetricsResponse([
                ['pageNumber' => 1, 'text' => 'Answer 1 with detailed working', 'questions' => [['id' => '1', 'text' => 'Question 1']], 'metadata' => [], 'confidence' => 0.9, 'fromCache' => false],
            ])
        );

        $pdfRenderer = $this->createMock(PdfPageRendererInterface::class);
        $pdfRenderer->method('isAvailable')->willReturn(true);
        $questionExtractor = $this->createMock(QuestionExtractor::class);

        $service = new AnalysisProcessingService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AnalysisRepository::class),
            $mongoRepo,
            $this->createStub(OcrCacheRepository::class),
            $textExtractor,
            $questionExtractor,
            $answerSheetExtractor,
            $qualityValidator,
            $meaningfulTextDetector,
            $documentClassifier,
            $openAiVision,
            $visionOcr,
            $pdfRenderer,
            new NullLogger(),
            $this->createStub(MessageBusInterface::class),
            $storageResolver
        );

        try {
            $service->processAnalysis($pdfPath, 'student-123');
            $analysis = $mongoRepo->findByAnalysisIdAndStudentId($pdfPath, 'student-123');
            $this->assertSame('NEEDS_REVIEW', $analysis->getStatus());
        } finally {
            if (file_exists($pdfPath)) {
                unlink($pdfPath);
            }
        }
    }

    public function testOcrOpenAiInsufficientQuotaForPdfTasknodeSucceeds(): void
    {
        $pdfPath = $this->createTempPdf();
        [$mongoRepo, $storageResolver] = $this->createOcrServiceCommonMocks($pdfPath);

        $textExtractor = $this->getScannedPdfTextExtractorMock();
        $meaningfulTextDetector = $this->getMeaningfulTextDetectorMock();
        $answerSheetExtractor = $this->getAnswerSheetExtractorMock(true);
        $qualityValidator = $this->getQualityValidatorMock(true);
        $documentClassifier = $this->getDocumentClassifierMock();

        $openAiVision = $this->createMock(OpenAiVisionProvider::class);
        $openAiVision->expects($this->once())->method('processPdf')
            ->willThrowException(VisionApiException::insufficientQuota('quota exceeded'));

        $visionOcr = $this->createMock(VisionOCRServiceInterface::class);
        $visionOcr->expects($this->once())->method('processPdf')->willReturn(
            $this->getOcrMetricsResponse([
                ['pageNumber' => 1, 'text' => 'Answer 1 with detailed working', 'questions' => [['id' => '1', 'text' => 'Question 1']], 'metadata' => [], 'confidence' => 0.9, 'fromCache' => false],
            ])
        );

        $pdfRenderer = $this->createMock(PdfPageRendererInterface::class);
        $pdfRenderer->method('isAvailable')->willReturn(true);
        $questionExtractor = $this->createMock(QuestionExtractor::class);

        $service = new AnalysisProcessingService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AnalysisRepository::class),
            $mongoRepo,
            $this->createStub(OcrCacheRepository::class),
            $textExtractor,
            $questionExtractor,
            $answerSheetExtractor,
            $qualityValidator,
            $meaningfulTextDetector,
            $documentClassifier,
            $openAiVision,
            $visionOcr,
            $pdfRenderer,
            new NullLogger(),
            $this->createStub(MessageBusInterface::class),
            $storageResolver
        );

        try {
            $service->processAnalysis($pdfPath, 'student-123');
            $analysis = $mongoRepo->findByAnalysisIdAndStudentId($pdfPath, 'student-123');
            $this->assertSame('NEEDS_REVIEW', $analysis->getStatus());
        } finally {
            if (file_exists($pdfPath)) {
                unlink($pdfPath);
            }
        }
    }

    public function testOcrOpenAiRateLimitForPdfTasknodeSucceeds(): void
    {
        $pdfPath = $this->createTempPdf();
        [$mongoRepo, $storageResolver] = $this->createOcrServiceCommonMocks($pdfPath);

        $textExtractor = $this->getScannedPdfTextExtractorMock();
        $meaningfulTextDetector = $this->getMeaningfulTextDetectorMock();
        $answerSheetExtractor = $this->getAnswerSheetExtractorMock(true);
        $qualityValidator = $this->getQualityValidatorMock(true);
        $documentClassifier = $this->getDocumentClassifierMock();

        $openAiVision = $this->createMock(OpenAiVisionProvider::class);
        $openAiVision->expects($this->once())->method('processPdf')
            ->willThrowException(VisionApiException::rateLimit('rate limited'));

        $visionOcr = $this->createMock(VisionOCRServiceInterface::class);
        $visionOcr->expects($this->once())->method('processPdf')->willReturn(
            $this->getOcrMetricsResponse([
                ['pageNumber' => 1, 'text' => 'Answer 1 with detailed working', 'questions' => [['id' => '1', 'text' => 'Question 1']], 'metadata' => [], 'confidence' => 0.9, 'fromCache' => false],
            ])
        );

        $pdfRenderer = $this->createMock(PdfPageRendererInterface::class);
        $pdfRenderer->method('isAvailable')->willReturn(true);
        $questionExtractor = $this->createMock(QuestionExtractor::class);

        $service = new AnalysisProcessingService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AnalysisRepository::class),
            $mongoRepo,
            $this->createStub(OcrCacheRepository::class),
            $textExtractor,
            $questionExtractor,
            $answerSheetExtractor,
            $qualityValidator,
            $meaningfulTextDetector,
            $documentClassifier,
            $openAiVision,
            $visionOcr,
            $pdfRenderer,
            new NullLogger(),
            $this->createStub(MessageBusInterface::class),
            $storageResolver
        );

        try {
            $service->processAnalysis($pdfPath, 'student-123');
            $analysis = $mongoRepo->findByAnalysisIdAndStudentId($pdfPath, 'student-123');
            $this->assertSame('NEEDS_REVIEW', $analysis->getStatus());
        } finally {
            if (file_exists($pdfPath)) {
                unlink($pdfPath);
            }
        }
    }

    public function testOcrBothProvidersFailForPdf(): void
    {
        $pdfPath = $this->createTempPdf();
        [$mongoRepo, $storageResolver] = $this->createOcrServiceCommonMocks($pdfPath);

        $textExtractor = $this->getScannedPdfTextExtractorMock();
        $meaningfulTextDetector = $this->getMeaningfulTextDetectorMock();
        $answerSheetExtractor = $this->getAnswerSheetExtractorMock(false);
        $qualityValidator = $this->getQualityValidatorMock(true);
        $documentClassifier = $this->getDocumentClassifierMock();

        $openAiVision = $this->createMock(OpenAiVisionProvider::class);
        $openAiVision->expects($this->once())->method('processPdf')
            ->willThrowException(VisionApiException::serverError('server error'));

        $visionOcr = $this->createMock(VisionOCRServiceInterface::class);
        $visionOcr->expects($this->once())->method('processPdf')
            ->willThrowException(new \RuntimeException('Tasknode failed'));

        $pdfRenderer = $this->createMock(PdfPageRendererInterface::class);
        $pdfRenderer->method('isAvailable')->willReturn(true);
        $questionExtractor = $this->createMock(QuestionExtractor::class);

        $service = new AnalysisProcessingService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AnalysisRepository::class),
            $mongoRepo,
            $this->createStub(OcrCacheRepository::class),
            $textExtractor,
            $questionExtractor,
            $answerSheetExtractor,
            $qualityValidator,
            $meaningfulTextDetector,
            $documentClassifier,
            $openAiVision,
            $visionOcr,
            $pdfRenderer,
            new NullLogger(),
            $this->createStub(MessageBusInterface::class),
            $storageResolver
        );

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Both OpenAI Vision and Tasknode VisionOCRService failed');
            $service->processAnalysis($pdfPath, 'student-123');
        } finally {
            if (file_exists($pdfPath)) {
                unlink($pdfPath);
            }
        }
    }

    public function testOcrOpenAiVisionSucceedsForImage(): void
    {
        $imagePath = sys_get_temp_dir() . '/test_ocr_image_' . uniqid() . '.png';
        file_put_contents($imagePath, 'fake-png');
        [$mongoRepo, $storageResolver] = $this->createOcrServiceCommonMocks($imagePath, false);

        $textExtractor = $this->getScannedPdfTextExtractorMock();
        $meaningfulTextDetector = $this->getMeaningfulTextDetectorMock();
        $answerSheetExtractor = $this->getAnswerSheetExtractorMock(true);
        $qualityValidator = $this->getQualityValidatorMock(true);
        $documentClassifier = $this->getDocumentClassifierMock();

        $openAiVision = $this->createMock(OpenAiVisionProvider::class);
        $openAiVision->expects($this->once())->method('processPageImage')->willReturn(
            $this->getOcrMetricsResponse([
                ['pageNumber' => 1, 'text' => 'Answer 1 with detailed working', 'questions' => [['id' => '1', 'text' => 'Question 1']], 'metadata' => [], 'confidence' => 0.9, 'fromCache' => false],
            ])
        );

        $visionOcr = $this->createMock(VisionOCRServiceInterface::class);
        $visionOcr->expects($this->never())->method('processPageImage');

        $pdfRenderer = $this->createMock(PdfPageRendererInterface::class);
        $questionExtractor = $this->createMock(QuestionExtractor::class);

        $service = new AnalysisProcessingService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AnalysisRepository::class),
            $mongoRepo,
            $this->createStub(OcrCacheRepository::class),
            $textExtractor,
            $questionExtractor,
            $answerSheetExtractor,
            $qualityValidator,
            $meaningfulTextDetector,
            $documentClassifier,
            $openAiVision,
            $visionOcr,
            $pdfRenderer,
            new NullLogger(),
            $this->createStub(MessageBusInterface::class),
            $storageResolver
        );

        try {
            $service->processAnalysis($imagePath, 'student-123');
            $analysis = $mongoRepo->findByAnalysisIdAndStudentId($imagePath, 'student-123');
            $this->assertSame('NEEDS_REVIEW', $analysis->getStatus());
        } finally {
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
        }
    }

    public function testOcrOpenAiMinimalReturnsForImageTasknodeSucceeds(): void
    {
        $imagePath = sys_get_temp_dir() . '/test_ocr_image2_' . uniqid() . '.png';
        file_put_contents($imagePath, 'fake-png');
        [$mongoRepo, $storageResolver] = $this->createOcrServiceCommonMocks($imagePath, false);

        $textExtractor = $this->getScannedPdfTextExtractorMock();
        $meaningfulTextDetector = $this->getMeaningfulTextDetectorMock();
        $answerSheetExtractor = $this->getAnswerSheetExtractorMock(true);
        $qualityValidator = $this->getQualityValidatorMock(true);
        $documentClassifier = $this->getDocumentClassifierMock();

        $openAiVision = $this->createMock(OpenAiVisionProvider::class);
        $openAiVision->expects($this->once())->method('processPageImage')->willReturn(
            $this->getOcrMetricsResponse([
                ['pageNumber' => 1, 'text' => '', 'questions' => [], 'metadata' => [], 'confidence' => 0.0, 'fromCache' => false],
            ], '', [])
        );

        $visionOcr = $this->createMock(VisionOCRServiceInterface::class);
        $visionOcr->expects($this->once())->method('processPageImage')->willReturn(
            $this->getOcrMetricsResponse([
                ['pageNumber' => 1, 'text' => 'Answer 1 with detailed working', 'questions' => [['id' => '1', 'text' => 'Question 1']], 'metadata' => [], 'confidence' => 0.9, 'fromCache' => false],
            ])
        );

        $pdfRenderer = $this->createMock(PdfPageRendererInterface::class);
        $questionExtractor = $this->createMock(QuestionExtractor::class);

        $service = new AnalysisProcessingService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AnalysisRepository::class),
            $mongoRepo,
            $this->createStub(OcrCacheRepository::class),
            $textExtractor,
            $questionExtractor,
            $answerSheetExtractor,
            $qualityValidator,
            $meaningfulTextDetector,
            $documentClassifier,
            $openAiVision,
            $visionOcr,
            $pdfRenderer,
            new NullLogger(),
            $this->createStub(MessageBusInterface::class),
            $storageResolver
        );

        try {
            $service->processAnalysis($imagePath, 'student-123');
            $analysis = $mongoRepo->findByAnalysisIdAndStudentId($imagePath, 'student-123');
            $this->assertSame('NEEDS_REVIEW', $analysis->getStatus());
        } finally {
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
        }
    }

    public function testOcrOpenAiInsufficientQuotaForImageTasknodeSucceeds(): void
    {
        $imagePath = sys_get_temp_dir() . '/test_ocr_image3_' . uniqid() . '.png';
        file_put_contents($imagePath, 'fake-png');
        [$mongoRepo, $storageResolver] = $this->createOcrServiceCommonMocks($imagePath, false);

        $textExtractor = $this->getScannedPdfTextExtractorMock();
        $meaningfulTextDetector = $this->getMeaningfulTextDetectorMock();
        $answerSheetExtractor = $this->getAnswerSheetExtractorMock(true);
        $qualityValidator = $this->getQualityValidatorMock(true);
        $documentClassifier = $this->getDocumentClassifierMock();

        $openAiVision = $this->createMock(OpenAiVisionProvider::class);
        $openAiVision->expects($this->once())->method('processPageImage')
            ->willThrowException(VisionApiException::insufficientQuota('quota exceeded'));

        $visionOcr = $this->createMock(VisionOCRServiceInterface::class);
        $visionOcr->expects($this->once())->method('processPageImage')->willReturn(
            $this->getOcrMetricsResponse([
                ['pageNumber' => 1, 'text' => 'Answer 1 with detailed working', 'questions' => [['id' => '1', 'text' => 'Question 1']], 'metadata' => [], 'confidence' => 0.9, 'fromCache' => false],
            ])
        );

        $pdfRenderer = $this->createMock(PdfPageRendererInterface::class);
        $questionExtractor = $this->createMock(QuestionExtractor::class);

        $service = new AnalysisProcessingService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AnalysisRepository::class),
            $mongoRepo,
            $this->createStub(OcrCacheRepository::class),
            $textExtractor,
            $questionExtractor,
            $answerSheetExtractor,
            $qualityValidator,
            $meaningfulTextDetector,
            $documentClassifier,
            $openAiVision,
            $visionOcr,
            $pdfRenderer,
            new NullLogger(),
            $this->createStub(MessageBusInterface::class),
            $storageResolver
        );

        try {
            $service->processAnalysis($imagePath, 'student-123');
            $analysis = $mongoRepo->findByAnalysisIdAndStudentId($imagePath, 'student-123');
            $this->assertSame('NEEDS_REVIEW', $analysis->getStatus());
        } finally {
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
        }
    }

    public function testOcrBothProvidersFailForImage(): void
    {
        $imagePath = sys_get_temp_dir() . '/test_ocr_image4_' . uniqid() . '.png';
        file_put_contents($imagePath, 'fake-png');
        [$mongoRepo, $storageResolver] = $this->createOcrServiceCommonMocks($imagePath, false);

        $textExtractor = $this->getScannedPdfTextExtractorMock();
        $meaningfulTextDetector = $this->getMeaningfulTextDetectorMock();
        $answerSheetExtractor = $this->getAnswerSheetExtractorMock(false);
        $qualityValidator = $this->getQualityValidatorMock(true);
        $documentClassifier = $this->getDocumentClassifierMock();

        $openAiVision = $this->createMock(OpenAiVisionProvider::class);
        $openAiVision->expects($this->once())->method('processPageImage')
            ->willThrowException(VisionApiException::serverError('server error'));

        $visionOcr = $this->createMock(VisionOCRServiceInterface::class);
        $visionOcr->expects($this->once())->method('processPageImage')
            ->willThrowException(new \RuntimeException('Tasknode failed'));

        $pdfRenderer = $this->createMock(PdfPageRendererInterface::class);
        $questionExtractor = $this->createMock(QuestionExtractor::class);

        $service = new AnalysisProcessingService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AnalysisRepository::class),
            $mongoRepo,
            $this->createStub(OcrCacheRepository::class),
            $textExtractor,
            $questionExtractor,
            $answerSheetExtractor,
            $qualityValidator,
            $meaningfulTextDetector,
            $documentClassifier,
            $openAiVision,
            $visionOcr,
            $pdfRenderer,
            new NullLogger(),
            $this->createStub(MessageBusInterface::class),
            $storageResolver
        );

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Both OpenAI Vision and Tasknode VisionOCRService failed');
            $service->processAnalysis($imagePath, 'student-123');
        } finally {
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
        }
    }
}
