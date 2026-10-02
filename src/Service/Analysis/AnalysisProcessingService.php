<?php

declare(strict_types=1);

namespace App\Service\Analysis;

use App\Document\Analysis;
use App\Document\OcrCache;
use App\Document\Processing;
use App\DTO\Analysis\ExtractedAnswerSheetMetadata;
use App\DTO\Analysis\ExtractedAnswer;
use App\Entity\User;
use App\Exception\AnalysisNotFoundException;
use App\Exception\PdfRendererUnavailableException;
use App\Exception\VisionApiException;
use App\Message\Analysis\ProcessAnalysisMessage;
use App\Repository\AnalysisRepository;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use App\Repository\MongoDB\OcrCacheRepository;
use App\Service\Analysis\Processing\AnswerSheetExtractor;
use App\Service\Analysis\Processing\AnswerSheetDocumentClassifier;
use App\Service\Analysis\Processing\DocumentTextExtractor;
use App\Service\Analysis\Processing\DocumentTextExtractorInterface;
use App\Service\Analysis\Processing\ExtractionQualityValidator;
use App\Service\Analysis\Processing\MeaningfulTextDetector;
use App\Service\Analysis\Processing\OpenAiVisionProvider;
use App\Service\Analysis\Processing\PdfPageRendererInterface;
use App\Service\Analysis\Processing\ProvenanceValue;
use App\Service\Analysis\Processing\QuestionExtractor;
use App\Service\Analysis\Processing\VisionOCRServiceInterface;
use App\DTO\Analysis\DocumentClassificationResult;
use App\Enum\Analysis\TextSufficiencyDecision;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

readonly class AnalysisProcessingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AnalysisRepository $pgAnalysisRepository,
        private MongoAnalysisRepository $mongoAnalysisRepository,
        private OcrCacheRepository $ocrCacheRepository,
        private DocumentTextExtractorInterface $documentTextExtractor,
        private QuestionExtractor $questionExtractor,
        private AnswerSheetExtractor $answerSheetExtractor,
        private ExtractionQualityValidator $extractionQualityValidator,
        private MeaningfulTextDetector $meaningfulTextDetector,
        private AnswerSheetDocumentClassifier $documentClassifier,
        private OpenAiVisionProvider $openAiVisionProvider,
        private VisionOCRServiceInterface $visionOcrService,
        private ?PdfPageRendererInterface $pdfPageRenderer,
        private LoggerInterface $logger,
        private MessageBusInterface $messageBus,
        private \App\Service\Analysis\AnalysisStorageResolver $storageResolver,
    ) {
    }

public function processAnalysis(string $analysisId, string $studentId, bool $isRetry = false, int $attemptId = 0): void
    {
        $startTime = new \DateTimeImmutable();
        $timeout = 120; // 2 minutes in seconds
        
        // Get MongoDB analysis
        $mongoAnalysis = $this->mongoAnalysisRepository->findByAnalysisIdAndStudentId($analysisId, $studentId);
        if (!$mongoAnalysis instanceof Analysis) {
            throw new AnalysisNotFoundException('Analysis not found.');
        }

        // Check if answer sheet is uploaded (required)
        $answerSheet = $mongoAnalysis->getDocuments()->getAnswerSheet();
        if ($answerSheet->getStatus() !== 'UPLOADED') {
            throw new \RuntimeException('Answer sheet must be uploaded before processing.');
        }

        // Question paper is optional - process if available
        $questionPaper = $mongoAnalysis->getDocuments()->getQuestionPaper();
        $hasQuestionPaper = $questionPaper->getStatus() === 'UPLOADED';

        // Check current analysis status to determine if this is a retry
        $currentStatus = $mongoAnalysis->getStatus();
        $isRetryFromStatus = in_array($currentStatus, ['PUBLISHED', 'FAILED'], true);
        $isRetry = $isRetry || $isRetryFromStatus;
        
        $processing = $mongoAnalysis->getProcessing();
        $wasProcessingFailed = $processing->getStatus() === 'FAILED';

        if ($isRetry && !$wasProcessingFailed && $processing->getAttemptId() > 0 && $processing->getAttemptId() !== $attemptId) {
            $this->logger->warning('Newer attempt detected, aborting current attempt', [
                'analysis_id' => $analysisId,
                'message_attempt_id' => $attemptId,
                'current_attempt_id' => $processing->getAttemptId(),
            ]);
            return;
        }

        if (!$isRetry && $processing->getAttemptId() > 0 && $processing->getStatus() === 'PROCESSING') {
            $this->logger->warning('Active processing attempt detected, skipping', [
                'analysis_id' => $analysisId,
                'existing_attempt_id' => $processing->getAttemptId(),
            ]);
            return;
        }

        // Check if already processed or processing (but allow retry if previous failure)
        $alreadyProcessedStatuses = ['EXTRACTED', 'NEEDS_REVIEW', 'CONFIRMED', 'ANALYZING', 'ANALYZED'];
        if (in_array($currentStatus, array_merge(['PROCESSING'], $alreadyProcessedStatuses), true) && !$isRetry) {
            $this->logger->info('Analysis already processed or processing, skipping', [
                'analysis_id' => $analysisId,
                'current_status' => $currentStatus,
            ]);
            return;
        }

        // If retrying after failure, reset processing state but preserve retry count
        if ($isRetry && $wasProcessingFailed) {
            $this->logger->info('Retrying failed analysis', [
                'analysis_id' => $analysisId,
                'retry_count' => $processing->getRetryCount() ?? 0,
                'attempt_id' => $attemptId,
            ]);
            $processing->incrementRetryCount();
            $processing->setError(null);
        } else {
            // First attempt or non-retry: reset retry count
            $processing->setRetryCount(0);
        }
        
        // Store attempt ID for tracking
        $processing->setAttemptId($attemptId);
        $processing->setStartedAt(new \DateTimeImmutable());

        // Get file paths
        $this->logger->debug('Resolving file paths', [
            'analysis_id' => $analysisId,
            'answer_sheet_file_id' => $answerSheet->getFileId(),
            'question_paper_file_id' => $questionPaper->getFileId(),
            'has_question_paper' => $hasQuestionPaper,
        ]);
        
        $answerSheetPath = $this->getFilePath($answerSheet->getFileId(), $analysisId);
        $questionPaperPath = $hasQuestionPaper ? $this->getFilePath($questionPaper->getFileId(), $analysisId) : null;
        
        $this->logger->debug('File paths resolved', [
            'analysis_id' => $analysisId,
            'answer_sheet_path' => $answerSheetPath,
            'answer_sheet_exists' => file_exists($answerSheetPath),
            'answer_sheet_size' => file_exists($answerSheetPath) ? filesize($answerSheetPath) : 0,
            'question_paper_path' => $questionPaperPath,
            'question_paper_exists' => $questionPaperPath ? file_exists($questionPaperPath) : false,
            'question_paper_size' => $questionPaperPath && file_exists($questionPaperPath) ? filesize($questionPaperPath) : 0,
        ]);

        // Compute file hash for caching/idempotency
        $fileHash = $this->computeFileHash($answerSheetPath);
        $this->logger->debug('Computed file hash', [
            'analysis_id' => $analysisId,
            'file_hash' => $fileHash,
        ]);
        
        // Store file hash on analysis for future reference
        $mongoAnalysis->setAnswerSheetFileHash($fileHash);

        $this->logger->info('Starting document processing', [
            'analysis_id' => $analysisId,
            'student_id' => $studentId,
            'has_question_paper' => $hasQuestionPaper,
            'is_retry' => $isRetry,
            'file_hash' => $fileHash,
        ]);

        try {
            // Step 1: Extract text with SetaPDF
            $this->logger->debug('Starting answer sheet text extraction', [
                'analysis_id' => $analysisId,
                'file_path' => $answerSheetPath,
            ]);

            $processing->setStage('EXTRACTING_PDF');
            $this->mongoAnalysisRepository->save($mongoAnalysis);

            $answerSheetData = $this->documentTextExtractor->extract(
                $answerSheetPath,
                $this->getMimeTypeFromExtension($answerSheetPath)
            );

            $this->logger->debug('Answer sheet text extraction completed', [
                'analysis_id' => $analysisId,
                'has_extractable_text' => $answerSheetData['hasExtractableText'],
                'page_count' => $answerSheetData['pageCount'],
                'text_length' => strlen($answerSheetData['text']),
                'extraction_method' => $answerSheetData['extractionMethod'],
            ]);

            // Check timeout after text extraction
            $this->checkTimeout($startTime, $timeout, $mongoAnalysis, $analysisId);

            $extractionMethod = 'SETA_PDF';

            // Step 2: Meaningful text detection - determine if SetaPDF extraction
            // produced sufficient text for student-answer analysis
            $processing->setStage('CHECKING_TEXT_SUFFICIENCY');
            $this->mongoAnalysisRepository->save($mongoAnalysis);

            $textSufficiency = $this->meaningfulTextDetector->analyze($answerSheetData['pages']);

            $this->logger->info('Text sufficiency analysis', [
                'analysis_id' => $analysisId,
                'decision' => $textSufficiency['decision']->value,
                'reason' => $textSufficiency['reason'],
            ]);

            // Check timeout after text sufficiency
            $this->checkTimeout($startTime, $timeout, $mongoAnalysis, $analysisId);

            // Document Classification Gate is DISABLED - skip classification gate
            // All documents proceed to extraction regardless of document type
            // documentClassification field left as null
            $mongoAnalysis->setDocumentClassification(null);
            $this->mongoAnalysisRepository->save($mongoAnalysis);

            // Check timeout
            $this->checkTimeout($startTime, $timeout, $mongoAnalysis, $analysisId);

            // Update status to PROCESSING
            $mongoAnalysis->setStatus('PROCESSING');
            $processing->setStage('UPLOADED');
            $processing->setStatus('PROCESSING');
            $processing->setStartedAt(new \DateTimeImmutable());
            $processing->setError(null);
            $this->mongoAnalysisRepository->save($mongoAnalysis);
            $this->syncPostgresStatus($analysisId, 'PROCESSING');

            if ($textSufficiency['decision']->isSufficient()) {
                // Text is sufficient - proceed with structured extraction
                $processing->setStage('CHECKING_EXTRACTION');
                $this->mongoAnalysisRepository->save($mongoAnalysis);
                
                $answerSheetExtraction = $this->answerSheetExtractor->extractFromAnswerSheet($answerSheetData['pages']);
                
                // Validate if extraction is meaningful using the quality validator
                $qualityValidation = $this->extractionQualityValidator->validate($answerSheetExtraction, $answerSheetData['pages']);
                
                $this->logger->debug('Extraction quality validation', [
                    'analysis_id' => $analysisId,
                    'usable' => $qualityValidation['usable'],
                    'reason' => $qualityValidation['reason'],
                    'meaningful_answers' => $qualityValidation['meaningfulAnswerCount'],
                    'metadata_fields' => $qualityValidation['metadataCount'],
                ]);
                
                // If extraction not usable and we haven't tried OCR yet, run OCR fallback
                if (!$qualityValidation['usable']) {
                    $this->logger->warning('Extraction result not usable, attempting OCR fallback', [
                        'analysis_id' => $analysisId,
                        'reason' => $qualityValidation['reason'],
                        'meaningful_answers' => $qualityValidation['meaningfulAnswerCount'],
                    ]);
                    
                    if ($extractionMethod === 'SETA_PDF') {
                        $processing->setStage('OCR_REQUIRED');
                        $this->mongoAnalysisRepository->save($mongoAnalysis);
                        
                        // For PDF documents, verify that a PDF renderer is available BEFORE
                        // calling the paid Vision OCR provider. If the renderer is not
                        // available, fail with a controlled infrastructure error.
                        $mimeType = $this->getMimeTypeFromExtension($answerSheetPath);
                        if ($mimeType === 'application/pdf') {
                            if (!$this->pdfPageRenderer || !$this->pdfPageRenderer->isAvailable()) {
                                $this->logger->error('PDF renderer not available for handwriting OCR', [
                                    'analysis_id' => $analysisId,
                                    'file_hash' => $fileHash,
                                    'renderer_configured' => $this->pdfPageRenderer !== null,
                                ]);
    
                                $processing->setStage('EXTRACTION_FAILED');
                                $processing->setStatus('FAILED');
                                $processing->setError('Handwriting extraction is temporarily unavailable because PDF page rendering is not configured.');
                                $processing->setCompletedAt(new \DateTimeImmutable());
                                $mongoAnalysis->setStatus('FAILED');
                                $mongoAnalysis->setUpdatedAt(new \DateTimeImmutable());
                                $this->mongoAnalysisRepository->save($mongoAnalysis);
                                $this->syncPostgresStatus($analysisId, 'FAILED');

                                $this->logger->warning('Handwriting extraction unavailable - renderer not configured', [
                                    'analysis_id' => $analysisId,
                                ]);
                                return;
                            }
                        }
    
                        // Determine pages that need OCR (pages without meaningful content)
                        $pagesNeedingOcr = $this->determinePagesNeedingOcr($qualityValidation['pageAnalysis']);
                        
                        try {
                            $answerSheetData = $this->runOcrFallback($answerSheetPath, $mongoAnalysis, $fileHash, $pagesNeedingOcr);
                        } catch (PdfRendererUnavailableException $e) {
                            $this->logger->error('PDF renderer unavailable during OCR processing', [
                                'analysis_id' => $analysisId,
                                'error' => $e->getMessage(),
                            ]);

                            $processing->setStage('EXTRACTION_FAILED');
                            $processing->setStatus('FAILED');
                            $processing->setError('Handwriting extraction is temporarily unavailable because PDF page rendering is not configured.');
                            $processing->setCompletedAt(new \DateTimeImmutable());
                            $mongoAnalysis->setStatus('FAILED');
                            $mongoAnalysis->setUpdatedAt(new \DateTimeImmutable());
                            $this->mongoAnalysisRepository->save($mongoAnalysis);
                            $this->syncPostgresStatus($analysisId, 'FAILED');
    
                            $this->logger->warning('Handwriting extraction unavailable - renderer became unavailable during processing', [
                                'analysis_id' => $analysisId,
                            ]);
                            return;
                        }
    
                        $extractionMethod = 'VISION_OCR';
                        
                        // Re-extract with OCR data
                        $answerSheetExtraction = $this->answerSheetExtractor->extractFromAnswerSheet($answerSheetData['pages']);
                        
                        // Validate OCR result too
                        $ocrQualityValidation = $this->extractionQualityValidator->validate($answerSheetExtraction, $answerSheetData['pages']);
                        
                        if (!$ocrQualityValidation['usable']) {
                            $this->logger->error('Both SetaPDF and OCR extraction failed', [
                                'analysis_id' => $analysisId,
                                'ocr_reason' => $ocrQualityValidation['reason'],
                            ]);
                            $this->handleExtractionFailure($mongoAnalysis, $analysisId, 'Both digital text extraction and handwriting recognition failed to extract meaningful content.');
                            return;
                        }
} else {
                // OCR was already tried and failed
                $this->handleExtractionFailure($mongoAnalysis, $analysisId, 'Handwriting recognition failed to extract meaningful content.');
                return;
            }
        }
    } else {
        // VISUAL_EXTRACTION_REQUIRED: go directly to visual extraction (OpenAI Vision)
        // No classification gate - proceed directly to visual extraction
        $this->logger->info('Text insufficient for extraction, proceeding to visual extraction', [
            'analysis_id' => $analysisId,
        ]);
        
        $mimeType = $this->getMimeTypeFromExtension($answerSheetPath);
        if ($mimeType === 'application/pdf') {
            if (!$this->pdfPageRenderer || !$this->pdfPageRenderer->isAvailable()) {
                $this->logger->error('PDF renderer not available for handwriting OCR', [
                    'analysis_id' => $analysisId,
                    'file_hash' => $fileHash,
                    'renderer_configured' => $this->pdfPageRenderer !== null,
                ]);
    
                $processing->setStage('EXTRACTION_FAILED');
                $processing->setStatus('FAILED');
                $processing->setError('Handwriting extraction is temporarily unavailable because PDF page rendering is not configured.');
                $processing->setCompletedAt(new \DateTimeImmutable());
                $mongoAnalysis->setStatus('FAILED');
                $mongoAnalysis->setUpdatedAt(new \DateTimeImmutable());
                $this->mongoAnalysisRepository->save($mongoAnalysis);
                $this->syncPostgresStatus($analysisId, 'FAILED');

                $this->logger->warning('Handwriting extraction unavailable - renderer not configured', [
                    'analysis_id' => $analysisId,
                ]);
                return;
            }
        }
        
        // Check timeout before OCR fallback
        $this->checkTimeout($startTime, $timeout, $mongoAnalysis, $analysisId);
        
        // Use combined visual extraction if question paper is available
        if ($hasQuestionPaper && $questionPaperPath !== null) {
            $answerSheetData = $this->runCombinedVisualExtraction(
                $answerSheetPath,
                $questionPaperPath,
                $mongoAnalysis,
                $fileHash
            );
        } else {
            // Process all pages since text extraction was insufficient
            $pagesNeedingOcr = range(1, $answerSheetData['pageCount']);
            
            try {
                $answerSheetData = $this->runOcrFallback($answerSheetPath, $mongoAnalysis, $fileHash, $pagesNeedingOcr);
            } catch (PdfRendererUnavailableException $e) {
                $this->logger->error('PDF renderer unavailable during OCR processing', [
                    'analysis_id' => $analysisId,
                    'error' => $e->getMessage(),
                ]);
    
                $processing->setStage('EXTRACTION_FAILED');
                $processing->setStatus('FAILED');
                $processing->setError('Handwriting extraction is temporarily unavailable because PDF page rendering is not configured.');
                $processing->setCompletedAt(new \DateTimeImmutable());
                $mongoAnalysis->setStatus('FAILED');
                $mongoAnalysis->setUpdatedAt(new \DateTimeImmutable());
                $this->mongoAnalysisRepository->save($mongoAnalysis);
                $this->syncPostgresStatus($analysisId, 'FAILED');
    
                $this->logger->warning('Handwriting extraction unavailable - renderer became unavailable during processing', [
                    'analysis_id' => $analysisId,
                ]);
                return;
            }
        }
        
        $extractionMethod = 'VISION_OCR';
        
        // Re-extract with OCR data
        $answerSheetExtraction = $this->answerSheetExtractor->extractFromAnswerSheet($answerSheetData['pages']);
        
        // Validate OCR result too
        $ocrQualityValidation = $this->extractionQualityValidator->validate($answerSheetExtraction, $answerSheetData['pages']);
        
        if (!$ocrQualityValidation['usable']) {
            $this->logger->error('Both SetaPDF and OCR extraction failed', [
                'analysis_id' => $analysisId,
                'ocr_reason' => $ocrQualityValidation['reason'],
            ]);
            $this->handleExtractionFailure($mongoAnalysis, $analysisId, 'Both digital text extraction and handwriting recognition failed to extract meaningful content.');
            return;
        }
    }
    
    // Check timeout after extraction
    $this->checkTimeout($startTime, $timeout, $mongoAnalysis, $analysisId);
    
    $this->logger->debug('Answer sheet extraction completed', [
                'analysis_id' => $analysisId,
                'extraction_result' => $answerSheetExtraction['extractionResult'],
                'confidence' => $answerSheetExtraction['extractionConfidence'],
                'metadata_fields_detected' => $this->answerSheetExtractor->countMetadataFields($answerSheetExtraction['metadata']),
                'answers_detected' => count($answerSheetExtraction['answers']),
            ]);
            
            // Process question paper if available (optional)
            $extractedQuestions = [];
            $questionsWithAnswers = [];

            if ($hasQuestionPaper) {
                $questionPaperPath = $this->getFilePath($questionPaper->getFileId(), $analysisId);

                $this->logger->debug('Starting question paper text extraction', [
                    'analysis_id' => $analysisId,
                    'file_path' => $questionPaperPath,
                ]);
                
                $questionPaperData = $this->documentTextExtractor->extract(
                    $questionPaperPath,
                    $this->getMimeTypeFromExtension($questionPaperPath)
                );
                
                $this->logger->debug('Question paper text extraction completed', [
                    'analysis_id' => $analysisId,
                    'has_extractable_text' => $questionPaperData['hasExtractableText'],
                    'page_count' => $questionPaperData['pageCount'],
                    'text_length' => strlen($questionPaperData['text']),
                    'extraction_method' => $questionPaperData['extractionMethod'],
                ]);

                // Extract questions from question paper
                $processing->setStage('QUESTION_EXTRACTION');
                $this->mongoAnalysisRepository->save($mongoAnalysis);

                $extractedQuestions = $this->questionExtractor->extractQuestions(
                    $questionPaperData['pages'],
                    $answerSheetData['pages']
                );
                
                $this->logger->debug('Question extraction completed', [
                    'analysis_id' => $analysisId,
                    'questions_extracted' => count($extractedQuestions),
                ]);
                
                $this->logger->debug('Starting answer mapping', [
                    'analysis_id' => $analysisId,
                ]);
                
                $processing->setStage('ANSWER_MAPPING');
                $this->mongoAnalysisRepository->save($mongoAnalysis);

                $questionsWithAnswers = $this->questionExtractor->mapAnswersToQuestions(
                    $extractedQuestions,
                    $answerSheetData['pages']
                );
            } else {
                // Answer sheet only - extract answers directly
                $processing->setStage('ANSWER_EXTRACTION');
                $this->mongoAnalysisRepository->save($mongoAnalysis);
                
                $questionsWithAnswers = $this->questionExtractor->mapAnswersToQuestions(
                    [], // No questions from question paper
                    $answerSheetData['pages']
                );
            }

            // Save extracted metadata from answer sheet
            $processing->setStage('SAVING_METADATA');
            $this->mongoAnalysisRepository->save($mongoAnalysis);

            $this->saveExtractedAnswerSheetMetadata($mongoAnalysis, $answerSheetExtraction['metadata']);
            $this->saveExtractedAnswers($mongoAnalysis, $answerSheetExtraction['answers']);
            
            // Update exam with extracted questions if available
            if (!empty($questionsWithAnswers)) {
                $this->saveExtractedQuestions($mongoAnalysis, $questionsWithAnswers);
            }

            // Update MongoDB with extracted data
            if ($hasQuestionPaper) {
                $this->saveExtractedAnswerSheetMetadata($mongoAnalysis, $answerSheetExtraction['metadata']);
                $this->saveExtractedAnswers($mongoAnalysis, $answerSheetExtraction['answers']);
                $this->saveExtractedQuestions($mongoAnalysis, $questionsWithAnswers);
            } else {
                $this->saveExtractedAnswerSheetMetadata($mongoAnalysis, $answerSheetExtraction['metadata']);
                $this->saveExtractedAnswers($mongoAnalysis, $answerSheetExtraction['answers']);
            }

            // Update status to EXTRACTED then NEEDS_REVIEW
            $this->logger->debug('Saving extracted data to MongoDB', [
                'analysis_id' => $analysisId,
            ]);
            
            $mongoAnalysis->setStatus('EXTRACTED');
            $processing->setStage('VALIDATING_EXTRACTION');
            $processing->setStatus('EXTRACTED');
            $processing->setCompletedAt(new \DateTimeImmutable());
            
            // Set extraction method on exam
            $exam = $mongoAnalysis->getExam();
            $exam->setExtractionMethod($extractionMethod);
            
            // Store OCR metrics if available
            if (isset($answerSheetData['ocrMetrics'])) {
                $this->storeOcrMetrics($processing, $exam, $answerSheetData['ocrMetrics']);
            }
            
            $this->logger->debug('Saving MongoDB analysis document', [
                'analysis_id' => $analysisId,
            ]);
            $this->mongoAnalysisRepository->save($mongoAnalysis);
            $this->syncPostgresStatus($analysisId, 'EXTRACTED');

            // Finally set to NEEDS_REVIEW
            $this->logger->debug('Transitioning to NEEDS_REVIEW', [
                'analysis_id' => $analysisId,
            ]);
            
            $mongoAnalysis->setStatus('NEEDS_REVIEW');
            $processing->setStatus('NEEDS_REVIEW');
            
            $this->logger->debug('Saving MongoDB analysis document (NEEDS_REVIEW)', [
                'analysis_id' => $analysisId,
            ]);
            $this->mongoAnalysisRepository->save($mongoAnalysis);
            $this->syncPostgresStatus($analysisId, 'NEEDS_REVIEW');

            $this->logger->info('Analysis processing completed successfully', [
                'analysis_id' => $analysisId,
                'question_count' => count($questionsWithAnswers),
                'extraction_method' => $extractionMethod,
                'file_hash' => $fileHash,
            ]);

    } catch (\Throwable $e) {
        $currentStage = $processing->getStage() ?? 'unknown';
        $currentStatus = $processing->getStatus() ?? 'unknown';
        
        $this->logger->error('Analysis processing failed', [
            'analysis_id' => $analysisId,
            'student_id' => $studentId,
            'exception_class' => get_class($e),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'processing_stage' => $currentStage,
            'processing_status' => $currentStatus,
        ]);

        try {
            $this->assertAttemptMatches($processing, $attemptId, $analysisId);
        } catch (\RuntimeException $attemptMismatch) {
            $this->logger->warning('Skipping failure state update due to attempt mismatch', [
                'analysis_id' => $analysisId,
            ]);
            throw $e;
        }
        
        $processing->setStage('FAILED');
        $processing->setStatus('FAILED');
        $processing->setError('Document processing failed. Please try again.');
        $processing->setCompletedAt(new \DateTimeImmutable());
        
        // Transition to FAILED so user can retry via the retry endpoint
        $mongoAnalysis->setStatus('FAILED');
        $mongoAnalysis->setUpdatedAt(new \DateTimeImmutable());
        $this->mongoAnalysisRepository->save($mongoAnalysis);
        $this->syncPostgresStatus($analysisId, 'FAILED');
        
        throw $e;
    }
}

    public function triggerProcessing(string $analysisId, string $studentId): void
    {
        $message = \App\Message\Analysis\ProcessAnalysisMessage::create($analysisId, $studentId);
        $this->messageBus->dispatch($message);
    }

    public function retryProcessing(string $analysisId, string $studentId): void
    {
        // Get current retry count to use as attempt ID
        $mongoAnalysis = $this->mongoAnalysisRepository->findByAnalysisId($analysisId);
        if (!$mongoAnalysis) {
            throw new \LogicException('Analysis not found');
        }
        
        $processing = $mongoAnalysis->getProcessing();
        $attemptId = ($processing->getRetryCount() ?? 0) + 1;
        
        $message = \App\Message\Analysis\ProcessAnalysisMessage::createRetry($analysisId, $studentId, $attemptId);
        $this->messageBus->dispatch($message);
    }

    private function saveExtractedAnswerSheetMetadata(Analysis $mongoAnalysis, ExtractedAnswerSheetMetadata $metadata): void
    {
        $exam = $mongoAnalysis->getExam();
        
        $extractedMetadata = [];
        
        // Helper to add field if available
        $addField = function (string $key, ?ProvenanceValue $value) use (&$extractedMetadata) {
            if ($value !== null && $value->isAvailable()) {
                $extractedMetadata[$key] = $value->toArray();
            }
        };
        
        $addField('studentName', $metadata->studentName);
        $addField('rollNumber', $metadata->rollNumber);
        $addField('subject', $metadata->subject);
        $addField('examTitle', $metadata->examTitle);
        $addField('academicYear', $metadata->academicYear);
        $addField('class', $metadata->class);
        $addField('section', $metadata->section);
        $addField('totalMarks', $metadata->totalMarks);
        $addField('scoredMarks', $metadata->scoredMarks);
        $addField('examDate', $metadata->examDate);
        $addField('examSubType', $metadata->examSubType);
        $addField('instituteName', $metadata->instituteName);
        
        $exam->setExtractedMetadata($extractedMetadata);
        $exam->setExtractionResult($metadata->extractionResult);
        $exam->setExtractionConfidence($metadata->extractionConfidence);
        $mongoAnalysis->setUpdatedAt(new \DateTimeImmutable());
        $this->mongoAnalysisRepository->save($mongoAnalysis);
    }

    private function saveExtractedAnswers(Analysis $mongoAnalysis, array $answers): void
    {
        $exam = $mongoAnalysis->getExam();
        
        $extractedAnswers = [];
        foreach ($answers as $answer) {
            $extractedAnswers[] = [
                'questionNumber' => $answer->questionNumber,
                'answerText' => $answer->answerText,
                'confidence' => $answer->confidence,
                'sourcePage' => $answer->page,
                'source' => [
                    'page' => $answer->page,
                    'bbox' => null,
                ],
            ];
        }
        
        $exam->setExtractedAnswers($extractedAnswers);
        $exam->setAnswerCount(count($extractedAnswers));
        $exam->setExtractedAt(new \DateTimeImmutable());
        $exam->setExtractionVersion('1.0');
        
        $mongoAnalysis->setUpdatedAt(new \DateTimeImmutable());
        $this->mongoAnalysisRepository->save($mongoAnalysis);
    }

    private function saveExtractedQuestions(Analysis $mongoAnalysis, array $questions): void
    {
        $exam = $mongoAnalysis->getExam();
        
        $extractedQuestions = [];
        foreach ($questions as $question) {
            $extractedQuestions[] = $question->toArray();
        }
        
        $exam->setExtractedQuestions($extractedQuestions);
        $exam->setQuestionCount(count($extractedQuestions));
        
        $mongoAnalysis->setUpdatedAt(new \DateTimeImmutable());
        $this->mongoAnalysisRepository->save($mongoAnalysis);
    }

    private function getFilePath(string $fileId, string $analysisId): string
    {
        try {
            $path = $this->storageResolver->getFilePathByFileId($analysisId, $fileId);
            $this->logger->debug('Resolved file path', [
                'analysis_id' => $analysisId,
                'file_id' => $fileId,
                'resolved_path' => $path,
                'exists' => file_exists($path),
                'size' => file_exists($path) ? filesize($path) : 0,
            ]);
            return $path;
        } catch (\RuntimeException $e) {
            $this->logger->error('File path resolution failed', [
                'analysis_id' => $analysisId,
                'file_id' => $fileId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function getMimeTypeFromExtension(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return match (strtolower(pathinfo($filePath, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
    }

    private function syncPostgresStatus(string $analysisId, string $status): void
    {
        try {
            $this->entityManager->beginTransaction();
            $pgAnalysis = $this->pgAnalysisRepository->findByAnalysisId($analysisId);
            if ($pgAnalysis) {
                $pgAnalysis->setStatus($status);
                $this->entityManager->flush();
                $this->entityManager->commit();
            }
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            $this->logger->error('Failed to update PostgreSQL analysis status', [
                'analysis_id' => $analysisId,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - MongoDB is source of truth, log and continue
        }
    }

    private function computeFileHash(string $filePath): string
    {
        return hash_file('sha256', $filePath);
    }

    /**
     * Determine which pages need OCR based on quality validation results
     *
     * @param array<array{page: int, hasMeaningfulContent: bool, textLength: int, answerCount: int, placeholderCount: int}> $pageAnalysis
     * @return array<int> Page numbers that need OCR
     */
    private function determinePagesNeedingOcr(array $pageAnalysis): array
    {
        $pagesNeedingOcr = [];
        
        foreach ($pageAnalysis as $page) {
            // Page needs OCR if it has no meaningful content
            if (!$page['hasMeaningfulContent']) {
                $pagesNeedingOcr[] = $page['page'];
            }
        }
        
        return $pagesNeedingOcr;
    }

    private function runOcrFallback(string $filePath, Analysis $mongoAnalysis, string $fileHash, ?array $pagesToProcess = null, bool $skipOpenAi = false): array
    {
        $this->checkTimeoutFromProcessing($mongoAnalysis->getProcessing());
        
        $processing = $mongoAnalysis->getProcessing();
        
        $processing->setStage('PREPARING_PAGES');
        $this->mongoAnalysisRepository->save($mongoAnalysis);
        
        $this->logger->info('Preparing pages for visual extraction', [
            'analysis_id' => $mongoAnalysis->getAnalysisId(),
            'file' => $filePath,
            'file_hash' => $fileHash,
            'pages_to_process' => $pagesToProcess,
        ]);
        
        $processing->setStage('EXTRACTING_HANDWRITING');
        $this->mongoAnalysisRepository->save($mongoAnalysis);
        
        $ocrResult = null;
        
        if (!$skipOpenAi) {
            $this->logger->info('Starting OpenAI Vision extraction (primary)', [
                'analysis_id' => $mongoAnalysis->getAnalysisId(),
                'file_hash' => $fileHash,
            ]);
            
            $ocrResult = $this->tryOpenAiVision($filePath, $fileHash, $pagesToProcess);
        } else {
            $this->logger->info('Skipping OpenAI Vision due to insufficient quota, falling back to Tasknode', [
                'analysis_id' => $mongoAnalysis->getAnalysisId(),
            ]);
        }
        
        // If OpenAI Vision fails or is skipped, fall back to Tasknode VisionOCRService
        if ($ocrResult === null) {
            $this->logger->warning('OpenAI Vision failed or skipped, falling back to Tasknode VisionOCRService', [
                'analysis_id' => $mongoAnalysis->getAnalysisId(),
            ]);
            
            $ocrResult = $this->tryTasknodeVision($filePath, $fileHash, $pagesToProcess);
        }
        
        if ($ocrResult === null) {
            $this->handleExtractionFailure($mongoAnalysis, $mongoAnalysis->getAnalysisId(), 'Both OpenAI Vision and Tasknode VisionOCRService failed to extract content.');
            throw new \RuntimeException('Both OpenAI Vision and Tasknode VisionOCRService failed');
        }
        
        $this->logger->info('Visual extraction completed', [
            'analysis_id' => $mongoAnalysis->getAnalysisId(),
            'pages_processed' => count($ocrResult['pages'] ?? []),
            'questions_found' => count($ocrResult['questions'] ?? []),
            'confidence' => $ocrResult['confidence'] ?? 0.0,
            'ocr_metrics' => $ocrResult['ocrMetrics'] ?? null,
            'extraction_method' => $ocrResult['extractionMethod'] ?? 'UNKNOWN',
        ]);
        
        // Convert OCR result to the same format as documentTextExtractor
        $pages = [];
        foreach ($ocrResult['pages'] ?? [] as $page) {
            $pages[] = [
                'page' => $page['pageNumber'] ?? 1,
                'text' => $page['text'] ?? '',
                'questions' => $page['questions'] ?? [],
            ];
        }

        return [
            'text' => implode("\n", array_column($pages, 'text')),
            'pages' => $pages,
            'pageCount' => count($pages),
            'hasExtractableText' => !empty(trim(implode("\n", array_column($pages, 'text')))),
            'extractionMethod' => $ocrResult['extractionMethod'] ?? 'VISUAL_EXTRACTION',
            'ocrMetrics' => $ocrResult['ocrMetrics'] ?? null,
        ];
    }
    
    private function tryOpenAiVision(string $filePath, string $fileHash, ?array $pagesToProcess): ?array
    {
        try {
            $mimeType = $this->getMimeTypeFromExtension($filePath);
            $ocrResult = match ($mimeType) {
                'application/pdf' => $this->openAiVisionProvider->processPdf($filePath, $fileHash, $pagesToProcess),
                'image/jpeg', 'image/png' => $this->openAiVisionProvider->processPageImage($filePath, 1, $fileHash),
                default => throw new \InvalidArgumentException('Unsupported file type for OpenAI Vision: ' . $mimeType),
            };
            
            $totalQuestions = count($ocrResult['questions'] ?? []);
            $totalTextLength = strlen($ocrResult['text'] ?? '');
            
            if ($totalQuestions === 0 && $totalTextLength < 50) {
                $this->logger->warning('OpenAI Vision returned minimal content', [
                    'questions' => $totalQuestions,
                    'text_length' => $totalTextLength,
                ]);
                return null;
            }
            
            return $ocrResult;
        } catch (VisionApiException $e) {
            $this->logger->error('OpenAI Vision unavailable', [
                'error' => $e->getMessage(),
                'error_type' => $e->getErrorType(),
                'is_insufficient_quota' => $e->isInsufficientQuota(),
                'is_rate_limit' => $e->isRateLimit(),
            ]);
            return null;
        } catch (\Throwable $e) {
            $this->logger->error('OpenAI Vision extraction failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
private function tryTasknodeVision(string $filePath, string $fileHash, ?array $pagesToProcess): ?array
    {
        try {
            $mimeType = $this->getMimeTypeFromExtension($filePath);
            $ocrResult = match ($mimeType) {
                'application/pdf' => $this->visionOcrService->processPdf($filePath, $fileHash, $pagesToProcess),
                'image/jpeg', 'image/png' => $this->visionOcrService->processPageImage($filePath, 1, $fileHash),
                default => throw new \InvalidArgumentException('Unsupported file type for Tasknode Vision: ' . $mimeType),
            };
            
            return $ocrResult;
        } catch (\Throwable $e) {
            $this->logger->error('Tasknode VisionOCRService extraction failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
    /**
     * Run combined visual extraction with both answer sheet and question paper.
     * Uses OpenAI Vision for simultaneous extraction + evaluation.
     */
    private function runCombinedVisualExtraction(
        string $answerSheetPath,
        string $questionPaperPath,
        Analysis $mongoAnalysis,
        string $fileHash
    ): array {
        $this->checkTimeoutFromProcessing($mongoAnalysis->getProcessing());
        
        $processing = $mongoAnalysis->getProcessing();
        
        $processing->setStage('PREPARING_PAGES');
        $this->mongoAnalysisRepository->save($mongoAnalysis);
        
        $this->logger->info('Preparing pages for combined visual extraction', [
            'analysis_id' => $mongoAnalysis->getAnalysisId(),
            'answer_sheet' => $answerSheetPath,
            'question_paper' => $questionPaperPath,
            'file_hash' => $fileHash,
        ]);
        
        $processing->setStage('EXTRACTING_HANDWRITING');
        $this->mongoAnalysisRepository->save($mongoAnalysis);
        
        $this->logger->info('Starting OpenAI Vision combined extraction + evaluation', [
            'analysis_id' => $mongoAnalysis->getAnalysisId(),
            'file_hash' => $fileHash,
        ]);
        
        try {
            $combinedResult = $this->openAiVisionProvider->processCombined(
                $answerSheetPath,
                $fileHash,
                $questionPaperPath
            );
            
            $this->logger->info('OpenAI Vision combined extraction completed', [
                'analysis_id' => $mongoAnalysis->getAnalysisId(),
                'pages_processed' => count($combinedResult['pages'] ?? []),
                'questions_found' => count($combinedResult['pages'] ?? []),
                'confidence' => $combinedResult['confidence'] ?? 0.0,
                'has_evaluations' => !empty($combinedResult['pages'][0]['questions'][0]['evaluation'] ?? null),
                'has_current_exam_analysis' => $this->hasCurrentExamAnalysis($combinedResult['currentExamAnalysis'] ?? null),
            ]);
            
            // Convert to standard format for AnswerSheetExtractor
            $pages = [];
            foreach ($combinedResult['pages'] ?? [] as $page) {
                $pages[] = [
                    'page' => $page['pageNumber'] ?? 1,
                    'text' => $page['text'] ?? '',
                    'questions' => $page['questions'] ?? [],
                ];
            }
            
            return [
                'text' => implode("\n", array_column($pages, 'text')),
                'pages' => $pages,
                'pageCount' => count($pages),
                'hasExtractableText' => !empty(trim(implode("\n", array_column($pages, 'text')))),
                'extractionMethod' => 'OPENAI_VISION_COMBINED',
                'ocrMetrics' => $combinedResult['ocrMetrics'] ?? null,
                'currentExamAnalysis' => $combinedResult['currentExamAnalysis'] ?? null,
            ];
        } catch (VisionApiException $e) {
            $skipOpenAi = $e->isInsufficientQuota();
            $this->logger->error('OpenAI Vision combined extraction failed', [
                'analysis_id' => $mongoAnalysis->getAnalysisId(),
                'error' => $e->getMessage(),
                'error_type' => $e->getErrorType(),
                'is_insufficient_quota' => $e->isInsufficientQuota(),
                'skip_openai_retry' => $skipOpenAi,
            ]);

            $pagesNeedingOcr = range(1, $mongoAnalysis->getExam()->getPagesDetected() ?? 1);
            return $this->runOcrFallback($answerSheetPath, $mongoAnalysis, $fileHash, $pagesNeedingOcr, $skipOpenAi);
        } catch (\Throwable $e) {
            $this->logger->error('OpenAI Vision combined extraction failed, falling back to standard OCR', [
                'analysis_id' => $mongoAnalysis->getAnalysisId(),
                'error' => $e->getMessage(),
            ]);

            $pagesNeedingOcr = range(1, $mongoAnalysis->getExam()->getPagesDetected() ?? 1);
            return $this->runOcrFallback($answerSheetPath, $mongoAnalysis, $fileHash, $pagesNeedingOcr);
        }
    }
    
    /**
     * Store current exam analysis from combined extraction.
     */
    private function storeCurrentExamAnalysis(Analysis $mongoAnalysis, array $combinedResult): void
    {
        $exam = $mongoAnalysis->getExam();
        
        // Extract question evaluations from combined result
        $questionEvaluations = [];
        foreach ($combinedResult['pages'] ?? [] as $page) {
            foreach ($page['questions'] ?? [] as $question) {
                if (isset($question['evaluation'])) {
                    $questionEvaluations[] = [
                        'questionNumber' => $question['questionNumber'] ?? '',
                        'subQuestion' => $question['subQuestion'] ?? null,
                        'resultStatus' => $question['evaluation']['resultStatus'] ?? 'NOT_EVALUATABLE',
                        'marksAwarded' => $question['evaluation']['marksAwarded'] ?? 0,
                        'maxMarks' => $question['evaluation']['maxMarks'] ?? ($question['marks'] ?? 0),
                        'answerQuality' => $question['evaluation']['answerQuality'] ?? 0.0,
                        'reasoningQuality' => $question['evaluation']['reasoningQuality'] ?? 0.0,
                        'confidence' => $question['evaluation']['confidence'] ?? 0.0,
                        'errorType' => $question['evaluation']['errorType'] ?? 'UNKNOWN',
                        'errorDescription' => $question['evaluation']['errorDescription'] ?? null,
                        'difficulty' => $question['evaluation']['difficulty'] ?? 'UNKNOWN',
                        'difficultyConfidence' => $question['evaluation']['difficultyConfidence'] ?? 0.0,
                        'topics' => $question['evaluation']['topics'] ?? [],
                        'concepts' => $question['evaluation']['concepts'] ?? [],
                        'misconception' => $question['evaluation']['misconception'] ?? [
                            'identified' => false,
                            'description' => null,
                            'confidence' => null,
                        ],
                        'questionKnownFromPaper' => $question['questionKnownFromPaper'] ?? false,
                    ];
                }
            }
        }
        
        $currentExamAnalysis = $combinedResult['currentExamAnalysis'] ?? [
            'strengths' => [],
            'weaknesses' => [],
            'needsAttention' => [],
            'conceptGaps' => [],
            'recommendations' => [],
        ];
        
        // Store in aiAnalysis field
        $existingAiAnalysis = $mongoAnalysis->getAiAnalysis() ?? [];
        $existingAiAnalysis['currentExamAnalysis'] = $currentExamAnalysis;
        $existingAiAnalysis['questionEvaluations'] = $questionEvaluations;
        $existingAiAnalysis['source'] = 'OPENAI_VISION_COMBINED';
        $existingAiAnalysis['generatedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $existingAiAnalysis['hasEvaluations'] = !empty($questionEvaluations);
        
        $mongoAnalysis->setAiAnalysis($existingAiAnalysis);
        
        $this->logger->info('Stored current exam analysis from combined visual extraction', [
            'analysis_id' => $mongoAnalysis->getAnalysisId(),
            'strengths_count' => count($currentExamAnalysis['strengths'] ?? []),
            'weaknesses_count' => count($currentExamAnalysis['weaknesses'] ?? []),
            'recommendations_count' => count($currentExamAnalysis['recommendations'] ?? []),
            'question_evaluations_count' => count($questionEvaluations),
        ]);
    }

    private function storeOcrMetrics(Processing $processing, \App\Document\Exam $exam, array $ocrMetrics): void
    {
        $processing->setPagesDetected($ocrMetrics['pagesDetected'] ?? null);
        $processing->setPagesProcessed($ocrMetrics['pagesProcessed'] ?? null);
        $processing->setPagesSkipped($ocrMetrics['pagesSkipped'] ?? null);
        $processing->setPagesAttempted($ocrMetrics['pagesAttempted'] ?? null);
        $processing->setPagesFailed($ocrMetrics['pagesFailed'] ?? null);
        $processing->setStoppedReason($ocrMetrics['stoppedReason'] ?? null);
        $processing->setOcrPagesProcessed($ocrMetrics['ocrPagesProcessed'] ?? null);
        $processing->setOcrCacheHits($ocrMetrics['ocrCacheHits'] ?? null);
        $processing->setOcrAttempts($ocrMetrics['ocrAttempts'] ?? null);
        $processing->setOcrRetryCount($ocrMetrics['ocrRetryCount'] ?? null);
        
        $exam->setPagesDetected($ocrMetrics['pagesDetected'] ?? null);
        $exam->setPagesProcessed($ocrMetrics['pagesProcessed'] ?? null);
        $exam->setPagesSkipped($ocrMetrics['pagesSkipped'] ?? null);
        $exam->setPagesAttempted($ocrMetrics['pagesAttempted'] ?? null);
        $exam->setPagesFailed($ocrMetrics['pagesFailed'] ?? null);
        $exam->setStoppedReason($ocrMetrics['stoppedReason'] ?? null);
        $exam->setOcrPagesProcessed($ocrMetrics['ocrPagesProcessed'] ?? null);
        $exam->setOcrCacheHits($ocrMetrics['ocrCacheHits'] ?? null);
        $exam->setOcrAttempts($ocrMetrics['ocrAttempts'] ?? null);
        $exam->setOcrRetryCount($ocrMetrics['ocrRetryCount'] ?? null);
    }

    private function handleExtractionFailure(Analysis $mongoAnalysis, string $analysisId, string $message): void
    {
        $processing = $mongoAnalysis->getProcessing();

        $processing->setStage('EXTRACTION_FAILED');
        $processing->setStatus('FAILED');
        $processing->setError($message);
        $processing->setCompletedAt(new \DateTimeImmutable());

        // Initialize empty extraction fields
        $exam = $mongoAnalysis->getExam();
        $exam->setExtractedMetadata([]);
        $exam->setExtractedAnswers([]);
        $exam->setExtractedQuestions([]);
        $exam->setExtractedAt(new \DateTimeImmutable());
        $exam->setExtractionVersion('1.0');
        $exam->setQuestionCount(0);
        $exam->setAnswerCount(0);
        $exam->setExtractionResult('EXTRACTION_FAILED');
        $exam->setExtractionConfidence(0.0);
        $exam->setExtractionMethod('NONE');

        // Transition to FAILED - user can retry via retry endpoint
        $mongoAnalysis->setStatus('FAILED');
        $mongoAnalysis->setUpdatedAt(new \DateTimeImmutable());
        $this->mongoAnalysisRepository->save($mongoAnalysis);
        $this->syncPostgresStatus($analysisId, 'FAILED');

        $this->logger->error('Extraction failed', [
            'analysis_id' => $analysisId,
            'message' => $message,
        ]);
    }
    
    /**
     * Classify the uploaded document as answer sheet, question paper, or unknown.
     */
    private function classifyDocument(string $answerSheetPath, string $fileHash, ?string $questionPaperPath = null): DocumentClassificationResult
    {
        $mimeType = $this->getMimeTypeFromExtension($answerSheetPath);
        
        // First, try to extract text using SetaPDF for classification (only for PDFs)
        if ($mimeType === 'application/pdf') {
            try {
                $textExtraction = $this->documentTextExtractor->extract($answerSheetPath, $mimeType);
                
                if ($textExtraction['hasExtractableText'] && strlen($textExtraction['text']) > 50) {
                    // Use text-based classification
                    return $this->documentClassifier->classifyFromText(
                        $textExtraction['text'],
                        $textExtraction['pageCount']
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Text extraction failed for classification, will use vision', [
                    'analysis_id' => null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Fall back to vision-based classification for scanned/handwritten docs and images
        try {
            // Use OpenAI Vision for classification + extraction in one call if question paper available
            if ($questionPaperPath !== null && $this->openAiVisionProvider->supportsMimeType($mimeType)) {
                $visionResult = $this->openAiVisionProvider->processCombined(
                    $answerSheetPath,
                    $fileHash,
                    $questionPaperPath
                );
                return $this->documentClassifier->classifyFromVision($visionResult);
            } else {
                // Just classify the answer sheet
                $visionResult = match ($mimeType) {
                    'application/pdf' => $this->openAiVisionProvider->processPdf($answerSheetPath, $fileHash),
                    'image/jpeg', 'image/png' => $this->openAiVisionProvider->processPageImage($answerSheetPath, 1, $fileHash),
                    default => throw new \InvalidArgumentException('Unsupported file type for vision classification: ' . $mimeType),
                };
                return $this->documentClassifier->classifyFromVision($visionResult);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Vision classification failed', [
                'error' => $e->getMessage(),
            ]);
            
            // Last resort: Tasknode vision
            try {
                $visionResult = match ($mimeType) {
                    'application/pdf' => $this->visionOcrService->processPdf($answerSheetPath, $fileHash),
                    'image/jpeg', 'image/png' => $this->visionOcrService->processPageImage($answerSheetPath, 1, $fileHash),
                    default => throw new \InvalidArgumentException('Unsupported file type for Tasknode vision: ' . $mimeType),
                };
                return $this->documentClassifier->classifyFromVision($visionResult);
            } catch (\Throwable $e2) {
                $this->logger->error('All classification methods failed', [
                    'error' => $e2->getMessage(),
                ]);
                
                // Return unknown if all methods fail
                return DocumentClassificationResult::unknown(0.1, ['all_classification_methods_failed' => true]);
            }
        }
    }
    
    /**
     * Classify document using vision (OpenAI Vision) for scanned/handwritten documents.
     * Used when SetaPDF text extraction is insufficient (VISUAL_EXTRACTION_REQUIRED).
     */
    private function classifyDocumentFromVision(string $answerSheetPath, string $fileHash, ?string $questionPaperPath = null): DocumentClassificationResult
    {
        $mimeType = $this->getMimeTypeFromExtension($answerSheetPath);

        // Use OpenAI Vision for classification + extraction in one call if question paper available
        try {
            if ($questionPaperPath !== null && $this->openAiVisionProvider->supportsMimeType($mimeType)) {
                $visionResult = $this->openAiVisionProvider->processCombined(
                    $answerSheetPath,
                    $fileHash,
                    $questionPaperPath
                );
                return $this->documentClassifier->classifyFromVision($visionResult);
            } else {
                // Just classify the answer sheet
                $visionResult = match ($mimeType) {
                    'application/pdf' => $this->openAiVisionProvider->processPdf($answerSheetPath, $fileHash),
                    'image/jpeg', 'image/png' => $this->openAiVisionProvider->processPageImage($answerSheetPath, 1, $fileHash),
                    default => throw new \InvalidArgumentException('Unsupported file type for vision classification: ' . $mimeType),
                };
                return $this->documentClassifier->classifyFromVision($visionResult);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Vision classification failed', [
                'error' => $e->getMessage(),
            ]);

            // Last resort: Tasknode vision
            try {
                $visionResult = match ($mimeType) {
                    'application/pdf' => $this->visionOcrService->processPdf($answerSheetPath, $fileHash),
                    'image/jpeg', 'image/png' => $this->visionOcrService->processPageImage($answerSheetPath, 1, $fileHash),
                    default => throw new \InvalidArgumentException('Unsupported file type for Tasknode vision: ' . $mimeType),
                };
                return $this->documentClassifier->classifyFromVision($visionResult);
            } catch (\Throwable $e2) {
                $this->logger->error('All classification methods failed', [
                    'error' => $e2->getMessage(),
                ]);

                // Return unknown if all methods fail
                return DocumentClassificationResult::unknown(0.1, ['all_classification_methods_failed' => true]);
            }
        }
    }

    /**
     * Extract structured answers from classification result (visual extraction path).
     */
    private function extractAnswersFromClassification(DocumentClassificationResult $classification, int $pageCount): array
    {
        $answers = [];
        $metadata = new \App\DTO\Analysis\ExtractedAnswerSheetMetadata();

        foreach ($classification->toArray()['questions'] ?? [] as $q) {
            $answers[] = new \App\DTO\Analysis\ExtractedAnswer(
                questionNumber: $q['questionNumber'] ?? '',
                answerText: $q['answerText'] ?? '',
                page: $q['page'] ?? 1,
                confidence: $q['evaluation']['confidence'] ?? $classification->confidence,
                provenance: \App\Service\Analysis\Processing\ProvenanceValue::Extracted,
            );
        }

        return [
            'metadata' => $metadata,
            'answers' => $answers,
            'extractionResult' => 'VISUAL_EXTRACTION',
            'extractionConfidence' => $classification->confidence,
        ];
    }

    /**
     * Handle a rejected document - stop processing and return appropriate response.
     */
    private function handleRejectedDocument(Analysis $mongoAnalysis, string $analysisId, DocumentClassificationResult $classification): void
    {
        $processing = $mongoAnalysis->getProcessing();
        
        $processing->setStage('CLASSIFICATION_REJECTED');
        $processing->setStatus('REJECTED');
        $processing->setError($classification->reason);
        
        // Clear any extracted data
        $exam = $mongoAnalysis->getExam();
        $exam->setExtractedMetadata([]);
        $exam->setExtractedAnswers([]);
        $exam->setExtractedQuestions([]);
        $exam->setExtractedAt(new \DateTimeImmutable());
        $exam->setExtractionVersion('1.0');
        $exam->setQuestionCount(0);
        $exam->setAnswerCount(0);
        $exam->setExtractionResult('REJECTED');
        $exam->setExtractionConfidence($classification->confidence);
        $exam->setExtractionMethod('CLASSIFICATION_REJECTED');
        
        // Transition to REJECTED state
        $mongoAnalysis->setStatus('REJECTED');
        $this->mongoAnalysisRepository->save($mongoAnalysis);
        $this->syncPostgresStatus($analysisId, 'REJECTED');
        
        $this->logger->warning('Document rejected by classification', [
            'analysis_id' => $analysisId,
            'document_type' => $classification->documentType->value,
            'reason' => $classification->reason,
            'confidence' => $classification->confidence,
        ]);
    }

    private function checkTimeout(\DateTimeImmutable $startTime, int $timeoutSeconds, Analysis $mongoAnalysis, string $analysisId): void
    {
        $elapsed = (new \DateTimeImmutable())->getTimestamp() - $startTime->getTimestamp();
        if ($elapsed > $timeoutSeconds) {
            $this->logger->error('Processing timeout exceeded', [
                'analysis_id' => $analysisId,
                'elapsed_seconds' => $elapsed,
                'timeout_seconds' => $timeoutSeconds,
            ]);
            
            $processing = $mongoAnalysis->getProcessing();
            $processing->setStage('TIMEOUT');
            $processing->setStatus('FAILED');
            $processing->setError("Analysis processing timed out after {$timeoutSeconds} seconds. Please try again.");
            
            $mongoAnalysis->setStatus('FAILED');
            $this->mongoAnalysisRepository->save($mongoAnalysis);
            $this->syncPostgresStatus($analysisId, 'FAILED');
            
            throw new \RuntimeException("Processing timeout exceeded ({$timeoutSeconds} seconds)");
        }
    }

    private function checkTimeoutFromProcessing(Processing $processing): void
    {
        $startTime = $processing->getStartedAt();
        if (!$startTime) {
            return;
        }
        
        $timeoutSeconds = 120; // 2 minutes
        $elapsed = (new \DateTimeImmutable())->getTimestamp() - $startTime->getTimestamp();
        if ($elapsed > $timeoutSeconds) {
            $this->logger->error('Processing timeout exceeded in sub-operation', [
                'elapsed_seconds' => $elapsed,
                'timeout_seconds' => $timeoutSeconds,
            ]);

            throw new \RuntimeException("Processing timeout exceeded ({$timeoutSeconds} seconds)");
        }
    }

    private function assertAttemptMatches(Processing $processing, int $attemptId, string $analysisId): void
    {
        if ($processing->getAttemptId() !== null && $processing->getAttemptId() !== $attemptId) {
            $this->logger->warning('Attempt ID mismatch - another attempt may have started', [
                'analysis_id' => $analysisId,
                'expected_attempt_id' => $attemptId,
                'current_attempt_id' => $processing->getAttemptId(),
            ]);

            throw new \RuntimeException('Another processing attempt is in progress. Current attempt abandoned.');
        }
    }

    private function hasCurrentExamAnalysis(?array $currentExamAnalysis): bool
    {
        if (!is_array($currentExamAnalysis) || empty($currentExamAnalysis)) {
            return false;
        }

        $keys = ['strengths', 'weaknesses', 'needsAttention', 'conceptGaps', 'recommendations'];
        foreach ($keys as $key) {
            if (!empty($currentExamAnalysis[$key] ?? [])) {
                return true;
            }
        }

        return false;
    }
}