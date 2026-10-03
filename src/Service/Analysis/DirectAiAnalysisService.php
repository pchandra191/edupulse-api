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
use App\Exception\PdfRendererUnavailableException;
use App\Repository\AnalysisRepository;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use App\Repository\MongoDB\OcrCacheRepository;
use App\Service\Analysis\Processing\OpenAiDirectDocumentProvider;
use App\Service\Analysis\AnalysisStorageResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

readonly class DirectAiAnalysisService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AnalysisRepository $pgAnalysisRepository,
        private MongoAnalysisRepository $mongoAnalysisRepository,
        private OcrCacheRepository $ocrCacheRepository,
        private OpenAiDirectDocumentProvider $directDocumentProvider,
        private AnalysisStorageResolver $storageResolver,
        private LoggerInterface $logger,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * Direct mode document preparation (no OpenAI call).
     * Validates documents are uploaded and transitions to NEEDS_REVIEW for metadata confirmation.
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
        if (!in_array($currentStatus, ['CONFIRMED', 'NEEDS_REVIEW', 'PUBLISHED', 'FAILED'], true)) {
            throw new \LogicException("Cannot start AI analysis in status: {$currentStatus}");
        }

        $exam = $mongoAnalysis->getExam();
        $extractedMetadata = $exam->getExtractedMetadata();
        if (empty($extractedMetadata)) {
            throw new \LogicException('No extracted metadata found. Metadata must be confirmed first.');
        }

        $answerSheet = $mongoAnalysis->getDocuments()->getAnswerSheet();
        if ($answerSheet->getStatus() !== 'UPLOADED') {
            throw new \RuntimeException('Answer sheet must be uploaded before AI analysis.');
        }

        $questionPaper = $mongoAnalysis->getDocuments()->getQuestionPaper();
        $hasQuestionPaper = $questionPaper->getStatus() === 'UPLOADED';

        $answerSheetPath = $this->getFilePath($answerSheet->getFileId(), $analysisId);
        $questionPaperPath = $hasQuestionPaper ? $this->getFilePath($questionPaper->getFileId(), $analysisId) : null;

        $fileHash = $mongoAnalysis->getAnswerSheetFileHash();
        if (!$fileHash) {
            $fileHash = $this->computeFileHash($answerSheetPath);
            $mongoAnalysis->setAnswerSheetFileHash($fileHash);
        }

        $trustedMetadata = $this->buildTrustedMetadata($mongoAnalysis);

        $processing = $mongoAnalysis->getProcessing();
        $wasProcessingFailed = $processing->getStatus() === 'FAILED';
        $isRetryFromStatus = in_array($currentStatus, ['PUBLISHED', 'FAILED'], true);
        $isRetry = $isRetry || $isRetryFromStatus;

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

            $combinedResult = $this->directDocumentProvider->extractDocumentsWithPreliminaryAnalysis(
                $answerSheetPath,
                $questionPaperPath,
                $trustedMetadata
            );

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
}