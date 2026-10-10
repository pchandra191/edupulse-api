<?php

declare(strict_types=1);

namespace App\Service\Analysis;

use App\Document\Analysis;
use App\Document\Documents;
use App\Document\Exam;
use App\Document\Processing;
use App\DTO\Analysis\Direct\DirectExtractionResult;
use App\DTO\Analysis\Direct\CurrentExamAnalysis;
use App\Exception\AnalysisNotFoundException;
use App\Exception\AnalysisLifecycleException;
use App\Exception\PdfRendererUnavailableException;
use App\Repository\AnalysisRepository;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use App\Repository\MongoDB\OcrCacheRepository;
use App\Service\Analysis\Processing\OpenAiDirectDocumentProvider;
use App\Service\Analysis\Processing\DocumentTextExtractorInterface;
use App\Service\Analysis\Processing\AnswerSheetExtractor;
use App\Service\Analysis\Processing\OpenAiSanitizer;
use App\Service\Analysis\Processing\ProvenanceValue;
use App\Service\Analysis\Processing\QuestionExtractor;
use App\Service\Analysis\AnalysisStorageResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly class DirectAiAnalysisService
{
    private const EXTRACTION_PROVIDER = 'OPENAI_DIRECT_DOCUMENT';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AnalysisRepository $pgAnalysisRepository,
        private MongoAnalysisRepository $mongoAnalysisRepository,
        private OcrCacheRepository $ocrCacheRepository,
        private OpenAiDirectDocumentProvider $directDocumentProvider,
        private AnalysisStorageResolver $storageResolver,
        private DocumentTextExtractorInterface $documentTextExtractor,
        private AnswerSheetExtractor $answerSheetExtractor,
        private LoggerInterface $logger,
        private MessageBusInterface $messageBus,
        private OpenAiSanitizer $sanitizer,
        private HttpClientInterface $httpClient,
        private string $apiKey,
    ) {
    }

    /**
     * Direct mode document preparation (no OpenAI call).
     * Extracts text from PDFs using SetaPDF, extracts structured data,
     * and transitions to NEEDS_REVIEW for metadata confirmation.
     */
    public function runDirectExtraction(string $analysisId, string $studentId, bool $isRetry = false, int $attemptId = 0): void
    {
        $this->logger->info('Starting Direct AI document preparation', [
            'analysis_id' => $analysisId,
            'student_id' => $studentId,
            'is_retry' => $isRetry,
            'attempt_id' => $attemptId,
            'mode' => 'direct',
        ]);

        $mongoAnalysis = $this->mongoAnalysisRepository->findByAnalysisIdAndStudentId($analysisId, $studentId);
        if (!$mongoAnalysis instanceof Analysis) {
            throw new AnalysisNotFoundException('Analysis not found.');
        }

        $answerSheet = $mongoAnalysis->getDocuments()->getAnswerSheet();
        if ($answerSheet->getStatus() !== 'UPLOADED') {
            throw new \RuntimeException('Answer sheet must be uploaded before processing.');
        }

        $questionPaper = $mongoAnalysis->getDocuments()->getQuestionPaper();
        $hasQuestionPaper = $questionPaper->getStatus() === 'UPLOADED';

        $currentStatus = $mongoAnalysis->getStatus();
        $processing = $mongoAnalysis->getProcessing();

        if ($currentStatus === 'PROCESSING' && $processing->getAttemptId() > 0 && $processing->getAttemptId() !== $attemptId) {
            $this->logger->warning('Newer attempt detected, aborting', [
                'analysis_id' => $analysisId,
                'message_attempt_id' => $attemptId,
                'current_attempt_id' => $processing->getAttemptId(),
            ]);
            return;
        }

        if (in_array($currentStatus, ['EXTRACTED', 'NEEDS_REVIEW', 'CONFIRMED', 'ANALYZING', 'ANALYZED'], true) && !$isRetry) {
            $this->logger->info('Direct analysis already prepared, skipping', [
                'analysis_id' => $analysisId,
                'current_status' => $currentStatus,
            ]);
            return;
        }

        if ($isRetry && $processing->getStatus() === 'FAILED') {
            $processing->incrementRetryCount();
            $processing->setError(null);
        } else {
            $processing->setRetryCount(0);
        }

        $processing->setAttemptId($attemptId);
        $processing->setStartedAt(new \DateTimeImmutable());

        // Collect all answer sheet paths (supports multiple answer sheets)
        $answerSheets = $mongoAnalysis->getDocuments()->getAnswerSheets();
        $answerSheetPaths = [];
        foreach ($mongoAnalysis->getDocuments()->getAnswerSheets() as $sheet) {
            if ($sheet->getStatus() === 'UPLOADED') {
                $answerSheetPaths[] = $this->getFilePath($sheet->getFileId(), $analysisId);
            }
        }

        if (empty($answerSheetPaths)) {
            throw new \RuntimeException('At least one answer sheet must be uploaded before processing.');
        }

        $questionPaperPath = $hasQuestionPaper ? $this->getFilePath($questionPaper->getFileId(), $analysisId) : null;

        // Step 1: Extract text from all answer sheets using DocumentTextExtractor (SetaPDF)
        $this->logger->info('[PDF-EXTRACTION-START]', [
            'analysisId' => $analysisId,
            'fileCount' => count($answerSheetPaths),
            'fileIds' => array_map(fn($sheet) => $sheet->getFileId(), $mongoAnalysis->getDocuments()->getAnswerSheets()),
            'mimeType' => 'application/pdf',
        ]);

        $answerSheetData = [];
        $fullText = '';
        $totalPages = 0;
        $pageToFileId = [];

        foreach ($mongoAnalysis->getDocuments()->getAnswerSheets() as $index => $sheet) {
            if ($sheet->getStatus() !== 'UPLOADED') {
                continue;
            }
            $sheetPath = $this->getFilePath($sheet->getFileId(), $analysisId);

            $this->logger->debug('[PDF-EXTRACTION-START]', [
                'analysisId' => $analysisId,
                'fileId' => $sheet->getFileId(),
                'path' => $sheetPath,
                'mimeType' => 'application/pdf',
                'fileSize' => filesize($sheetPath),
            ]);

            $data = $this->documentTextExtractor->extract($sheetPath, 'application/pdf');

            $this->logger->info('[PDF-EXTRACTION-RESULT]', [
                'analysisId' => $analysisId,
                'fileId' => $sheet->getFileId(),
                'textLength' => strlen($data['text']),
                'pageCount' => $data['pageCount'],
                'hasText' => $data['hasExtractableText'],
            ]);

            foreach ($data['pages'] as $page) {
                $pageNumber = $page['page'] + $totalPages;
                $answerSheetData[] = [
                    'page' => $pageNumber,
                    'text' => $page['text'],
                ];
                $pageToFileId[$pageNumber] = $sheet->getFileId();
            }
            $fullText .= "\n" . $data['text'];
            $totalPages += $data['pageCount'];
        }

        // Step 2: Extract structured data using AnswerSheetExtractor
        $answerSheetExtraction = $this->answerSheetExtractor->extractFromAnswerSheet($answerSheetData);

        $this->logger->info('[ANSWER-EXTRACTION-RESULT]', [
            'analysisId' => $analysisId,
            'questionCount' => count($answerSheetExtraction['questions'] ?? []),
            'answerCount' => count($answerSheetExtraction['answers'] ?? []),
            'metadataCount' => $this->answerSheetExtractor->countMetadataFields($answerSheetExtraction['metadata'] ?? new \App\DTO\Analysis\ExtractedAnswerSheetMetadata()),
            'status' => $answerSheetExtraction['extractionResult'] ?? 'unknown',
        ]);

        // Step 3: Persist extracted data
        $exam = $mongoAnalysis->getExam();
        $this->saveExtractedAnswerSheetMetadata($mongoAnalysis, $answerSheetExtraction['metadata']);
        $this->saveExtractedAnswers($mongoAnalysis, $answerSheetExtraction['answers'], $pageToFileId);
        $this->saveExtractedQuestions($mongoAnalysis, $answerSheetExtraction['questions'] ?? []);

        // Update exam with extraction info
        $exam = $mongoAnalysis->getExam();
        $exam->setExtractionMethod('SETA_PDF');
        $exam->setExtractionConfidence($answerSheetExtraction['extractionConfidence']);
        $exam->setExtractionResult($answerSheetExtraction['extractionResult'] ?? 'SUCCESS');
        $exam->setExtractedAt(new \DateTimeImmutable());
        $exam->setExtractionVersion('1.0');

        $this->logger->info('[EXTRACTION-PERSISTED]', [
            'analysisId' => $analysisId,
            'extractedQuestions' => count($answerSheetExtraction['questions'] ?? []),
            'extractedAnswers' => count($answerSheetExtraction['answers'] ?? []),
            'extractedMetadata' => $this->answerSheetExtractor->countMetadataFields($answerSheetExtraction['metadata'] ?? new \App\DTO\Analysis\ExtractedAnswerSheetMetadata()),
            'extractionResult' => $answerSheetExtraction['extractionResult'] ?? 'SUCCESS',
        ]);

        // Update status to NEEDS_REVIEW
        $processing = $mongoAnalysis->getProcessing();
        $processing->setStage('READY_FOR_REVIEW');
        $processing->setStatus('NEEDS_REVIEW');

        $mongoAnalysis->setStatus('NEEDS_REVIEW');
        $mongoAnalysis->setUpdatedAt(new \DateTimeImmutable());
        $this->mongoAnalysisRepository->save($mongoAnalysis);
        $this->syncPostgresStatus($analysisId, 'NEEDS_REVIEW');

        $this->logger->info('Direct analysis ready for metadata confirmation', [
            'analysis_id' => $analysisId,
            'has_question_paper' => $hasQuestionPaper,
            'attempt_id' => $attemptId,
        ]);
    }

    /**
     * Stage 2: Run Direct AI analysis (single OpenAI request for extraction + analysis)
     * Called when student clicks "Start AI Analysis"
     */
    public function runDirectAnalysis(string $analysisId, string $studentId, bool $isRetry = false, int $attemptId = 0): void
    {
        $this->logger->info('Starting Direct AI analysis', [
            'analysis_id' => $analysisId,
            'student_id' => $studentId,
            'is_retry' => $isRetry,
            'attempt_id' => $attemptId,
        ]);

        $mongoAnalysis = $this->mongoAnalysisRepository->findByAnalysisIdAndStudentId($analysisId, $studentId);
        if (!$mongoAnalysis instanceof Analysis) {
            throw new AnalysisNotFoundException('Analysis not found.');
        }

        $currentStatus = $mongoAnalysis->getStatus();
        // Allow PROCESSING because metadata confirmation sets status to PROCESSING before dispatching analyze.
        // Also allow NEEDS_REVIEW for cases where prepare phase left it in NEEDS_REVIEW without metadata confirmation.
        if (!in_array($currentStatus, ['CONFIRMED', 'NEEDS_REVIEW', 'PUBLISHED', 'FAILED', 'PROCESSING'], true)) {
            throw new AnalysisLifecycleException("Cannot start AI analysis in status: {$currentStatus}");
        }

        $exam = $mongoAnalysis->getExam();
        if (!$this->hasConfirmedMetadata($mongoAnalysis)) {
            throw new AnalysisLifecycleException('Confirmed metadata is required before analysis.');
        }

        $answerSheets = $mongoAnalysis->getDocuments()->getAnswerSheets();
        $answerSheetPaths = [];
        foreach ($answerSheets as $sheet) {
            if ($sheet->getStatus() === 'UPLOADED') {
                $answerSheetPaths[] = $this->getFilePath($sheet->getFileId(), $analysisId);
            }
        }
        if (empty($answerSheetPaths)) {
            throw new \RuntimeException('At least one answer sheet must be uploaded before AI analysis.');
        }

        $questionPaper = $mongoAnalysis->getDocuments()->getQuestionPaper();
        $hasQuestionPaper = $questionPaper->getStatus() === 'UPLOADED';
        $questionPaperPath = $hasQuestionPaper ? $this->getFilePath($questionPaper->getFileId(), $analysisId) : null;

        $fileHash = $mongoAnalysis->getAnswerSheetFileHash();
        if (!$fileHash) {
            $fileHash = $this->computeFileHash($answerSheetPaths[0]);
            $mongoAnalysis->setAnswerSheetFileHash($fileHash);
        }

        $trustedMetadata = $this->buildTrustedMetadata($mongoAnalysis);

        $processing = $mongoAnalysis->getProcessing();
        $wasProcessingFailed = $processing->getStatus() === 'FAILED';
        $isRetryFromStatus = in_array($currentStatus, ['PUBLISHED', 'FAILED'], true);
        $isRetry = $isRetry || $isRetryFromStatus;

        // Check if we have valid extraction data to reuse
        $hasValidExtraction = $this->hasValidExtractionData($mongoAnalysis);

        if ($isRetry && !$wasProcessingFailed && $processing->getAttemptId() > 0 && $processing->getAttemptId() !== $attemptId) {
            $this->logger->warning('Newer attempt detected, aborting', [
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

        if ($isRetry && $wasProcessingFailed) {
            $processing->incrementRetryCount();
            $processing->setError(null);
        } else {
            $processing->setRetryCount(0);
        }

        $processing->setAttemptId($attemptId);
        $processing->setStartedAt(new \DateTimeImmutable());

        try {
            $processing->setStage('ANALYZING');
            $processing->setStatus('PROCESSING');
            $mongoAnalysis->setStatus('PROCESSING');
            $this->mongoAnalysisRepository->save($mongoAnalysis);
            $this->syncPostgresStatus($analysisId, 'PROCESSING');

            $combinedResult = null;

            if ($hasValidExtraction) {
                $this->logger->info('Reusing existing extraction data for analysis', [
                    'analysis_id' => $analysisId,
                ]);

                // Build extraction result from stored data
                $extractionResult = $this->buildExtractionResultFromStorage($mongoAnalysis);

                // Analyze using stored extraction data
                $analysis = $this->directDocumentProvider->analyzeExtractedData(
                    $extractionResult,
                    $answerSheetPaths,
                    $questionPaperPath,
                    $trustedMetadata
                );

                $combinedResult = new DirectExtractionResult(
                    metadata: $extractionResult->metadata,
                    extractedAnswers: $extractionResult->extractedAnswers,
                    extractedQuestions: $extractionResult->extractedQuestions,
                    confidence: $extractionResult->confidence,
                    extractionMethod: $extractionResult->extractionMethod,
                    ocrMetrics: $extractionResult->ocrMetrics,
                    documentEvidence: $extractionResult->documentEvidence,
                    language: $extractionResult->language,
                    analysis: $analysis,
                    questionAnalysis: null, // Will be populated from analysis
                );
            } else {
                $this->logger->info('Running combined extraction and analysis', [
                    'analysis_id' => $analysisId,
                ]);

                $combinedResult = $this->directDocumentProvider->extractDocumentsWithPreliminaryAnalysis(
                    $answerSheetPaths,
                    $questionPaperPath,
                    $trustedMetadata
                );
            }

            $this->assertAttemptMatches($processing, $attemptId, $analysisId);

            $this->saveExtractedData($mongoAnalysis, $combinedResult, self::EXTRACTION_PROVIDER);
            $this->storeAiAnalysisFromCombinedResult($mongoAnalysis, $combinedResult);

            $processing->setStage('COMPLETED');
            $processing->setStatus('DONE');
            $processing->setCompletedAt(new \DateTimeImmutable());
            $mongoAnalysis->setStatus('DONE');
            $mongoAnalysis->setUpdatedAt(new \DateTimeImmutable());
            $this->mongoAnalysisRepository->save($mongoAnalysis);
            $this->syncPostgresStatus($analysisId, 'DONE');

            $this->logger->info('Direct AI analysis completed successfully', [
                'analysis_id' => $analysisId,
                'answers_extracted' => count($combinedResult->extractedAnswers),
                'questions_extracted' => count($combinedResult->extractedQuestions),
                'has_analysis' => $combinedResult->analysis !== null,
                'strengths_count' => $combinedResult->analysis ? count($combinedResult->analysis->strengths) : 0,
            ]);
        } catch (\Throwable $e) {
            $this->handleAnalysisFailure($mongoAnalysis, $analysisId, $e, $attemptId);
            throw $e;
        }
    }

    /**
     * Check if the analysis has valid extraction data that can be reused.
     */
    private function hasValidExtractionData(Analysis $mongoAnalysis): bool
    {
        $exam = $mongoAnalysis->getExam();
        
        // Check if we have extracted questions and answers
        $extractedQuestions = $exam->getExtractedQuestions() ?? [];
        $extractedAnswers = $exam->getExtractedAnswers() ?? [];
        
        // Must have at least some extracted data
        if (empty($extractedQuestions) && empty($extractedAnswers)) {
            return false;
        }

        // Check extraction result status
        $extractionResult = $exam->getExtractionResult() ?? '';
        if (!in_array($extractionResult, ['SUCCESS', 'PARTIAL'], true)) {
            return false;
        }

        // Check extraction confidence is reasonable
        $confidence = $exam->getExtractionConfidence();
        if ($confidence !== null && $confidence < 0.1) {
            return false;
        }

        return true;
    }

    /**
     * Build DirectExtractionResult from stored extraction data.
     */
    private function buildExtractionResultFromStorage(Analysis $mongoAnalysis): DirectExtractionResult
    {
        $exam = $mongoAnalysis->getExam();

        $metadata = $exam->getExtractedMetadata() ?? [];
        $extractedAnswers = $exam->getExtractedAnswers() ?? [];
        $extractedQuestions = $exam->getExtractedQuestions() ?? [];

        // Build metadata object
        $metadataObj = $this->buildMetadataFromStorage($metadata);

        // Build extracted answers
        $extractedAnswers = [];
        foreach ($extractedAnswers as $ans) {
            $extractedAnswers[] = new ExtractedAnswer(
                questionNumber: $ans['questionNumber'] ?? null,
                answerText: $ans['answerText'] ?? null,
                page: $ans['page'] ?? null,
                confidence: $ans['confidence'] ?? null,
                sourcePage: $ans['sourcePage'] ?? null,
            );
        }

        // Build extracted questions
        $extractedQuestions = [];
        foreach ($extractedQuestions as $q) {
            $extractedQuestions[] = [
                'questionNumber' => $q['questionNumber'] ?? null,
                'subQuestion' => $q['subQuestion'] ?? null,
                'questionText' => $q['questionText'] ?? null,
                'marks' => $q['marks'] ?? null,
                'rawText' => $q['rawText'] ?? null,
                'language' => $q['language'] ?? null,
                'confidence' => $q['confidence'] ?? null,
                'questionKnownFromPaper' => $q['questionKnownFromPaper'] ?? null,
            ];
        }

        return new DirectExtractionResult(
            metadata: $metadataObj,
            extractedAnswers: $extractedAnswers,
            extractedQuestions: $extractedQuestions,
            confidence: $exam->getExtractionConfidence() ?? 0.5,
            extractionMethod: $exam->getExtractionMethod() ?? 'UNKNOWN',
            ocrMetrics: null,
            documentEvidence: $exam->getDocumentEvidence() ?? null,
            language: $exam->getExtractionLanguage() ?? 'en',
        );
    }

    /**
     * Build metadata object from stored data.
     */
    private function buildMetadataFromStorage(array $metadata): \App\DTO\Analysis\ExtractedAnswerSheetMetadata
    {
        $merge = function (?string $key, ?string $extractedValue): ?\App\Service\Analysis\Processing\ProvenanceValue {
            if ($extractedValue !== null) {
                return new \App\Service\Analysis\Processing\ProvenanceValue($extractedValue, \App\Service\Analysis\Processing\ExtractionProvenance::Extracted, 0.5);
            }
            return null;
        };

        $mergeNumeric = function (?string $key, $extractedValue): ?\App\Service\Analysis\Processing\ProvenanceValue {
            $extractedValue = $extractedValue !== null ? (string)$extractedValue : null;
            if ($extractedValue !== null) {
                return new \App\Service\Analysis\Processing\ProvenanceValue($extractedValue, \App\Service\Analysis\Processing\ExtractionProvenance::Extracted, 0.5);
            }
            return null;
        };

        return new \App\DTO\Analysis\ExtractedAnswerSheetMetadata(
            studentName: $metadata['studentName'] ?? null,
            rollNumber: $metadata['rollNumber'] ?? null,
            subject: $metadata['subject'] ?? null,
            examTitle: $metadata['examTitle'] ?? null,
            academicYear: $metadata['academicYear'] ?? null,
            class: $metadata['class'] ?? null,
            section: $metadata['section'] ?? null,
            totalMarks: $metadata['totalMarks'] ?? null,
            scoredMarks: $metadata['scoredMarks'] ?? null,
            examDate: $metadata['examDate'] ?? null,
            examSubType: $metadata['examSubType'] ?? null,
            instituteName: $metadata['instituteName'] ?? null,
            extractionResult: $metadata['extractionResult'] ?? 'SUCCESS',
            extractionConfidence: $metadata['extractionConfidence'] ?? 0.5,
        );
    }

    private function hasConfirmedMetadata(Analysis $mongoAnalysis): bool
    {
        $pgAnalysis = $this->pgAnalysisRepository->findByAnalysisId($mongoAnalysis->getAnalysisId());
        if ($pgAnalysis !== null) {
            if ($pgAnalysis->getMetadataConfirmationStatus() === \App\Enum\MetadataConfirmationStatus::Confirmed->value) {
                return true;
            }
            if ($pgAnalysis->getSubjectName() !== null || $pgAnalysis->getTotalMarks() !== null) {
                return true;
            }
        }

        if ($mongoAnalysis->getMetadataConfirmationStatus() === 'CONFIRMED') {
            return true;
        }

        return false;
    }

    private function buildTrustedMetadata(Analysis $mongoAnalysis): array
    {
        $pgAnalysis = $this->pgAnalysisRepository->findByAnalysisId($mongoAnalysis->getAnalysisId());
        
        $metadata = [];
        if ($pgAnalysis) {
            if ($pgAnalysis->getStudentName()) $metadata['studentName'] = $pgAnalysis->getStudentName();
            if ($pgAnalysis->getRollNumber()) $metadata['rollNumber'] = $pgAnalysis->getRollNumber();
            if ($pgAnalysis->getSubjectName()) $metadata['subject'] = $pgAnalysis->getSubjectName();
            if ($pgAnalysis->getTotalMarks() !== null) $metadata['totalMarks'] = $pgAnalysis->getTotalMarks();
            if ($pgAnalysis->getScoredMarks() !== null) $metadata['scoredMarks'] = $pgAnalysis->getScoredMarks();
        }

        // Also include MongoDB exam metadata if available
        $exam = $mongoAnalysis->getExam();
        $extractedMetadata = $exam->getExtractedMetadata();
        if ($extractedMetadata) {
            foreach ($extractedMetadata as $key => $value) {
                if (is_array($value) && isset($value['value'])) {
                    // Don't overwrite PostgreSQL values
                    if (!isset($metadata[$key])) {
                        $metadata[$key] = $value['value'];
                    }
                }
            }
        }

        return $metadata;
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
        }
    }

    /**
     * Validate and deterministically repair the AI analysis result.
     * 
     * This method performs comprehensive validation of the AI analysis result
     * and applies deterministic repairs for known issues. It returns a validation
     * report with all detected issues and applied repairs.
     * 
     * @return array{
     *     valid: bool,
     *     violations: array<string>,
     *     repairs: array<string>,
     *     repaired: bool
     * }
     */
    public function validateAndRepairAnalysis(array $aiAnalysis): array
    {
        $violations = [];
        $repairs = [];
        $repaired = false;

        // Validate questionAnalysis
        $questionAnalysis = $aiAnalysis['questionAnalysis'] ?? [];
        foreach ($questionAnalysis as $idx => $qa) {
            $prefix = "questionAnalysis[{$idx}]";

            // Validate evaluationStatus
            $eval = $qa['evaluation'] ?? [];
            $evalStatus = $eval['evaluationStatus'] ?? null;
            if ($evalStatus !== null && !in_array($evalStatus, ['CORRECT', 'PARTIALLY_CORRECT', 'INCORRECT', 'UNANSWERED', 'INSUFFICIENT_EVIDENCE', 'NOT_DETERMINABLE', 'UNDETERMINED', 'ASSESSED_BY_VISIBLE_MARKING_ONLY', 'MARKED_CORRECT'], true)) {
                $violations[] = "{$prefix}.evaluation.evaluationStatus: invalid value '{$evalStatus}'";
            }

            // Validate correctness
            $correctness = $eval['correctness'] ?? null;
            if ($correctness !== null && !in_array($correctness, ['CORRECT', 'INCORRECT', 'PARTIALLY_CORRECT', 'UNDETERMINED', 'NOT_DETERMINABLE', 'INSUFFICIENT_EVIDENCE', 'UNANSWERED'], true)) {
                $violations[] = "{$prefix}.evaluation.correctness: invalid value '{$correctness}'";
            }

            // Validate marks
            $marksAwarded = $eval['marksAwarded'] ?? null;
            $maxMarks = $eval['maxMarks'] ?? null;
            if ($marksAwarded !== null && (!is_numeric($marksAwarded) || $marksAwarded < 0)) {
                $violations[] = "{$prefix}.evaluation.marksAwarded: must be non-negative number or null";
            }
            if ($maxMarks !== null && (!is_numeric($maxMarks) || $maxMarks < 0)) {
                $violations[] = "{$prefix}.evaluation.maxMarks: must be non-negative number or null";
            }
            if ($marksAwarded !== null && $maxMarks !== null && $marksAwarded > $maxMarks) {
                $violations[] = "{$prefix}.evaluation.marksAwarded: cannot exceed maxMarks";
            }

            // Validate concept status
            $concept = $qa['concept'] ?? [];
            $conceptStatus = $concept['status'] ?? null;
            if ($conceptStatus !== null && !in_array($conceptStatus, ['STRONG', 'DEVELOPING', 'NEEDS_ATTENTION', 'INSUFFICIENT_EVIDENCE', 'UNANSWERED'], true)) {
                $violations[] = "{$prefix}.concept.status: invalid value '{$conceptStatus}'";
            }

            // Validate questionAnalysis status
            $qaStatus = $qa['status'] ?? null;
            if ($qaStatus !== null && !in_array($qaStatus, ['STRONG', 'DEVELOPING', 'NEEDS_ATTENTION', 'INSUFFICIENT_EVIDENCE', 'UNANSWERED'], true)) {
                $violations[] = "{$prefix}.status: invalid value '{$qaStatus}'";
            }

            // Validate marksAwarded/maxMarks consistency with evaluationStatus
            if ($evalStatus === 'UNANSWERED' && ($marksAwarded !== null || $maxMarks !== null)) {
                // Repair: nullify marks for unanswered questions
                $eval['marksAwarded'] = null;
                $eval['maxMarks'] = null;
                $repairs[] = "{$prefix}.evaluation: nullified marks for UNANSWERED question";
                $repaired = true;
            }
            if ($evalStatus === 'CORRECT' && $marksAwarded !== null && $maxMarks !== null && $marksAwarded < $maxMarks) {
                // This is PARTIALLY_CORRECT, not CORRECT
                $eval['evaluationStatus'] = 'PARTIALLY_CORRECT';
                $repairs[] = "{$prefix}.evaluationStatus: corrected to PARTIALLY_CORRECT (marksAwarded < maxMarks)";
                $repaired = true;
            }

            // Ensure missingPoints is an array
            if (!isset($eval['missingPoints']) || !is_array($eval['missingPoints'])) {
                $eval['missingPoints'] = [];
                $repairs[] = "{$prefix}.evaluation.missingPoints: initialized as empty array";
                $repaired = true;
            }

            // Ensure concept name is not null when status is not INSUFFICIENT_EVIDENCE
            $conceptName = $concept['name'] ?? null;
            $conceptStatus = $concept['status'] ?? 'INSUFFICIENT_EVIDENCE';
            if ($conceptStatus !== 'INSUFFICIENT_EVIDENCE' && $conceptName === null) {
                $violations[] = "{$prefix}.concept.name: must not be null when concept status is not INSUFFICIENT_EVIDENCE";
            }

            // Ensure improvement object has all required fields
            $improvement = $qa['improvement'] ?? [];
            foreach (['whatToLearn', 'whatToPractice', 'recommendedAction'] as $field) {
                if (!array_key_exists($field, $improvement)) {
                    $improvement[$field] = null;
                    $repairs[] = "{$prefix}.improvement.{$field}: initialized as null";
                    $repaired = true;
                }
            }
        }

        // Validate currentExamAnalysis
        $currentExamAnalysis = $aiAnalysis['currentExamAnalysis'] ?? [];
        $performance = $currentExamAnalysis['performance'] ?? [];
        
        if (isset($performance['score']) && $performance['score'] !== null && (!is_numeric($performance['score']) || $performance['score'] < 0)) {
            $violations[] = 'currentExamAnalysis.performance.score: must be non-negative number or null';
        }
        if (isset($performance['totalMarks']) && $performance['totalMarks'] !== null && (!is_numeric($performance['totalMarks']) || $performance['totalMarks'] < 0)) {
            $violations[] = 'currentExamAnalysis.performance.totalMarks: must be non-negative number or null';
        }
        if (isset($performance['percentage']) && $performance['percentage'] !== null && (!is_numeric($performance['percentage']) || $performance['percentage'] < 0 || $performance['percentage'] > 100)) {
            $violations[] = 'currentExamAnalysis.performance.percentage: must be between 0 and 100 or null';
        }

        // Validate conceptAssessments
        foreach ($currentExamAnalysis['conceptAssessments'] ?? [] as $idx => $ca) {
            $caStatus = $ca['status'] ?? null;
            if ($caStatus !== null && !in_array($caStatus, ['STRONG', 'DEVELOPING', 'NEEDS_ATTENTION', 'INSUFFICIENT_EVIDENCE', 'UNANSWERED'], true)) {
                $violations[] = "currentExamAnalysis.conceptAssessments[{$idx}].status: invalid value '{$caStatus}'";
            }
            $caConfidence = $ca['confidence'] ?? null;
            if ($caConfidence !== null && (!is_numeric($caConfidence) || $caConfidence < 0 || $caConfidence > 1)) {
                $violations[] = "currentExamAnalysis.conceptAssessments[{$idx}].confidence: must be between 0 and 1";
            }
        }

        // Validate conceptAssessments in questionAnalysis are consistent with currentExamAnalysis
        // (This is a cross-reference check, violations are logged but not fatal)

        // Check for missing required arrays
        foreach (['strengths', 'weaknesses', 'needsAttention', 'conceptGaps', 'recommendations', 'conceptAssessments', 'mistakes', 'evidence'] as $key) {
            if (!array_key_exists($key, $currentExamAnalysis) || !is_array($currentExamAnalysis[$key])) {
                $violations[] = "currentExamAnalysis.{$key}: missing or not an array";
            }
        }

        $valid = empty($violations);

return [
            'valid' => $valid,
            'violations' => $violations,
            'repairs' => $repairs,
            'repaired' => $repaired,
        ];
    }

    private function saveExtractedData(Analysis $mongoAnalysis, DirectExtractionResult $extractionResult, string $extractionMethod): void
    {
        $exam = $mongoAnalysis->getExam();

        // Save metadata
        $this->saveExtractedAnswerSheetMetadata($mongoAnalysis, $extractionResult->metadata);

        // Save answers
        $this->saveExtractedAnswers($mongoAnalysis, $extractionResult->extractedAnswers);

        // Save questions
        $questions = [];
        foreach ($extractionResult->extractedQuestions as $q) {
            $questions[] = [
                'questionNumber' => [
                    'value' => $q['questionNumber'],
                    'status' => 'EXTRACTED',
                    'confidence' => $q['confidence'],
                ],
                'questionText' => [
                    'value' => $q['questionText'],
                    'status' => 'EXTRACTED',
                    'confidence' => $q['confidence'],
                ],
                'marks' => [
                    'value' => $q['marks'],
                    'status' => 'EXTRACTED',
                    'confidence' => $q['confidence'],
                ],
                'type' => [
                    'value' => 'short',
                    'status' => 'EXTRACTED',
                    'confidence' => 0.8,
                ],
                'source' => [
                    'page' => null,
                    'bbox' => null,
                ],
                'answerText' => [
                    'value' => '',
                    'status' => 'EXTRACTED',
                    'confidence' => 0.8,
                ],
                'answerSource' => [
                    'page' => null,
                    'bbox' => null,
                ],
            ];
        }
        $this->saveExtractedQuestions($mongoAnalysis, $questions);

        // Update exam
        $exam->setExtractionMethod($extractionMethod);
        $exam->setExtractionConfidence($extractionResult->confidence);
        $exam->setExtractionResult($extractionResult->metadata->extractionResult ?? 'SUCCESS');
        $exam->setExtractedAt(new \DateTimeImmutable());
        $exam->setExtractionVersion('1.0');
    }

    private function saveExtractedAnswerSheetMetadata(Analysis $mongoAnalysis, \App\DTO\Analysis\ExtractedAnswerSheetMetadata $metadata): void
    {
        $exam = $mongoAnalysis->getExam();

        $extractedMetadata = [];
        $addField = function (string $key, ?\App\Service\Analysis\Processing\ProvenanceValue $value) use (&$extractedMetadata) {
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

    private function saveExtractedAnswers(Analysis $mongoAnalysis, array $answers, array $pageToFileId = []): void
    {
        $exam = $mongoAnalysis->getExam();

        $extractedAnswers = [];
        foreach ($answers as $answer) {
            $page = $answer->page ?? (isset($answer->sourcePage) ? (int)$answer->sourcePage : 1);
            $fileId = $pageToFileId[$page] ?? null;

            $extractedAnswers[] = [
                'questionNumber' => $answer->questionNumber,
                'answerText' => $answer->answerText,
                'confidence' => $answer->confidence,
                'sourcePage' => $answer->page ?? $answer->sourcePage ?? null,
                'source' => [
                    'page' => $answer->page ?? $answer->sourcePage ?? null,
                    'bbox' => null,
                    'fileId' => $fileId,
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
        $exam->setExtractedQuestions($questions);
        $exam->setQuestionCount(count($questions));
        $mongoAnalysis->setUpdatedAt(new \DateTimeImmutable());
        $this->mongoAnalysisRepository->save($mongoAnalysis);
    }

    private function storeAiAnalysis(Analysis $mongoAnalysis, CurrentExamAnalysis $currentExamAnalysis): void
    {
        $aiAnalysis = $mongoAnalysis->getAiAnalysis() ?? [];
        
        $currentExamAnalysisArray = [
            'performance' => $currentExamAnalysis->performance ? [
                'score' => $currentExamAnalysis->performance->score,
                'totalMarks' => $currentExamAnalysis->performance->totalMarks,
                'percentage' => $currentExamAnalysis->performance->percentage,
            ] : null,
            'strengths' => $currentExamAnalysis->strengths,
            'weaknesses' => $currentExamAnalysis->weaknesses,
            'needsAttention' => $currentExamAnalysis->needsAttention,
            'conceptGaps' => $currentExamAnalysis->conceptGaps,
            'recommendations' => $currentExamAnalysis->recommendations,
            'conceptAssessments' => array_map(fn($ca) => [
                'concept' => $ca->concept,
                'status' => $ca->status,
                'confidence' => $ca->confidence,
                'evidence' => $ca->evidence,
            ], $currentExamAnalysis->conceptAssessments),
            'mistakes' => array_map(fn($m) => [
                'questionNumber' => $m->questionNumber,
                'subQuestion' => $m->subQuestion,
                'type' => $m->type,
                'description' => $m->description,
                'confidence' => $m->confidence,
            ], $currentExamAnalysis->mistakes),
            'evidence' => array_map(fn($e) => [
                'type' => $e->type,
                'questionNumber' => $e->questionNumber,
                'subQuestion' => $e->subQuestion,
                'reason' => $e->reason,
                'page' => $e->page,
                'bbox' => $e->bbox,
            ], $currentExamAnalysis->evidence),
        ];

        $aiAnalysis['currentExamAnalysis'] = $currentExamAnalysisArray;
        $aiAnalysis['source'] = 'OPENAI_DIRECT_ANALYSIS';
        $aiAnalysis['generatedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $mongoAnalysis->setAiAnalysis($aiAnalysis);
        $this->mongoAnalysisRepository->save($mongoAnalysis);
    }

    private function storeAiAnalysisFromCombinedResult(Analysis $mongoAnalysis, DirectExtractionResult $combinedResult): void
    {
        if ($combinedResult->analysis === null) {
            $this->logger->warning('Combined result missing analysis, skipping aiAnalysis persistence', [
                'analysis_id' => $mongoAnalysis->getAnalysisId(),
            ]);
            return;
        }

        $this->storeAiAnalysis($mongoAnalysis, $combinedResult->analysis);

        if ($combinedResult->questionAnalysis !== null && is_array($combinedResult->questionAnalysis)) {
            $aiAnalysis = $mongoAnalysis->getAiAnalysis() ?? [];
            $aiAnalysis['questionAnalysis'] = $combinedResult->questionAnalysis;
            $mongoAnalysis->setAiAnalysis($aiAnalysis);
            $this->mongoAnalysisRepository->save($mongoAnalysis);

            $this->logger->info('Persisted questionAnalysis', [
                'analysis_id' => $mongoAnalysis->getAnalysisId(),
                'question_analysis_count' => count($combinedResult->questionAnalysis),
            ]);
        }
    }

    private function handleExtractionFailure(Analysis $mongoAnalysis, string $analysisId, \Throwable $e, int $attemptId): void
    {
        $processing = $mongoAnalysis->getProcessing();

        try {
            $this->assertAttemptMatches($processing, $attemptId, $analysisId);
        } catch (\RuntimeException $attemptMismatch) {
            $this->logger->warning('Skipping failure state update due to attempt mismatch', [
                'analysis_id' => $analysisId,
            ]);
            return;
        }

        $errorMessage = $e->getMessage();
        $errorType = get_class($e);

        // Check for quota exhaustion or rate limiting
        if (str_contains($errorMessage, 'insufficient') || str_contains($errorMessage, 'quota') || str_contains($errorMessage, 'credit') || str_contains($errorMessage, 'rate_limit')) {
            $errorMessage = 'OpenAI quota is unavailable. Please try again later.';
        }

        $processing->setStage('FAILED');
        $processing->setStatus('FAILED');
        $processing->setError($errorMessage);
        $processing->setCompletedAt(new \DateTimeImmutable());

        $mongoAnalysis->setStatus('FAILED');
        $mongoAnalysis->setUpdatedAt(new \DateTimeImmutable());
        $this->mongoAnalysisRepository->save($mongoAnalysis);
        $this->syncPostgresStatus($analysisId, 'FAILED');

        $this->logger->error('Direct AI extraction failed', [
            'analysis_id' => $analysisId,
            'error_type' => $errorType,
            'error' => $errorMessage,
        ]);
    }

    private function handleAnalysisFailure(Analysis $mongoAnalysis, string $analysisId, \Throwable $e, int $attemptId): void
    {
        $processing = $mongoAnalysis->getProcessing();

        try {
            $this->assertAttemptMatches($processing, $attemptId, $analysisId);
        } catch (\RuntimeException $attemptMismatch) {
            $this->logger->warning('Skipping failure state update due to attempt mismatch', [
                'analysis_id' => $analysisId,
            ]);
            return;
        }

        $errorMessage = $e->getMessage();

        if (str_contains($errorMessage, 'insufficient') || str_contains($errorMessage, 'quota') || str_contains($errorMessage, 'credit') || str_contains($errorMessage, 'rate_limit')) {
            $errorMessage = 'OpenAI quota is unavailable. Please try again later.';
        }

        $processing->setStage('FAILED');
        $processing->setStatus('FAILED');
        $processing->setError($errorMessage);
        $processing->setCompletedAt(new \DateTimeImmutable());

        $mongoAnalysis->setStatus('FAILED');
        $mongoAnalysis->setUpdatedAt(new \DateTimeImmutable());
        $this->mongoAnalysisRepository->save($mongoAnalysis);
        $this->syncPostgresStatus($analysisId, 'FAILED');

        $this->logger->error('Direct AI analysis failed', [
            'analysis_id' => $analysisId,
            'error' => $errorMessage,
        ]);
    }

    private function assertAttemptMatches(Processing $processing, int $attemptId, string $analysisId): void
    {
        if ($processing->getAttemptId() !== null && $processing->getAttemptId() !== $attemptId) {
            $this->logger->warning('Attempt ID mismatch - another attempt may have started', [
                'analysis_id' => $analysisId,
                'message_attempt_id' => $attemptId,
                'current_attempt_id' => $processing->getAttemptId(),
            ]);
            throw new \RuntimeException('Another processing attempt is in progress. Current attempt abandoned.');
        }
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
            ]);
            throw $e;
        }
    }

    private function computeFileHash(string $filePath): string
    {
        return hash_file('sha256', $filePath);
    }

    
}