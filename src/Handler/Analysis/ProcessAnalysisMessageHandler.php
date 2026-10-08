<?php
declare(strict_types=1);

namespace App\Handler\Analysis;

use App\Document\Analysis;
use App\Message\Analysis\ProcessAnalysisMessage;
use App\Service\Analysis\AnalysisModeResolver;
use App\Service\Analysis\AnalysisProcessingRecoveryService;
use App\Service\Analysis\AnalysisProcessingService;
use App\Service\Analysis\DirectAiAnalysisService;
use App\Service\Analysis\Processing\OpenAiDirectDocumentProvider;
use App\Exception\VisionApiException;
use App\Repository\AnalysisRepository;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class ProcessAnalysisMessageHandler
{
    public function __construct(
        private AnalysisProcessingService $legacyProcessingService,
        private DirectAiAnalysisService $directAiAnalysisService,
        private AnalysisProcessingRecoveryService $recoveryService,
        private AnalysisModeResolver $modeResolver,
        private MongoAnalysisRepository $mongoAnalysisRepository,
        private AnalysisRepository $pgAnalysisRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessAnalysisMessage $message): void
    {
        $analysisId = $message->analysisId;
        $studentId = $message->studentId;
        $action = $message->action;
        $isRetry = $message->isRetry;
        $attemptId = $message->attemptId;

        $this->logger->info('Starting analysis processing', [
            'analysis_id' => $analysisId,
            'student_id' => $studentId,
            'action' => $action,
            'is_retry' => $isRetry,
            'attempt_id' => $attemptId,
            'ai_mode' => $this->modeResolver->getMode()->value,
        ]);

        try {
            $this->recoveryService->recoverIfStale($analysisId);

            if ($this->modeResolver->isDirectMode()) {
                $this->handleDirectMode($message);
            } else {
                $this->handleLegacyMode($message);
            }

            $this->logger->info('Analysis processing completed successfully', [
                'analysis_id' => $analysisId,
                'action' => $action,
                'attempt_id' => $attemptId,
                'engine' => $this->modeResolver->getMode()->value,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Analysis processing failed', [
                'analysis_id' => $analysisId,
                'student_id' => $studentId,
                'action' => $action,
                'attempt_id' => $attemptId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            try {
                $this->markAnalysisAsFailed($analysisId, $studentId, $e);
            } catch (\Throwable $markFailedError) {
                $this->logger->error('Failed to mark analysis as failed', [
                    'analysis_id' => $analysisId,
                    'student_id' => $studentId,
                    'error' => $markFailedError->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    private function handleDirectMode(ProcessAnalysisMessage $message): void
    {
        $analysisId = $message->analysisId;
        $studentId = $message->studentId;
        $action = $message->action;
        $isRetry = $message->isRetry;
        $attemptId = $message->attemptId;

        $this->logger->info('Starting analysis processing', [
            'analysis_id' => $analysisId,
            'action' => $action,
            'ai_mode' => 'direct',
            'is_retry' => $isRetry,
            'attempt_id' => $attemptId,
        ]);

        if ($action === 'prepare') {
            $this->logger->info("Analysis {$analysisId}: prepare handler started", [
                'analysis_id' => $analysisId,
                'attempt_id' => $attemptId,
            ]);
            $this->directAiAnalysisService->runDirectExtraction($analysisId, $studentId, $isRetry, $attemptId);
            $this->logger->info("Analysis {$analysisId}: SetaPDF preparation completed", [
                'analysis_id' => $analysisId,
                'attempt_id' => $attemptId,
            ]);
            return;
        }

        // action === 'analyze'
        $this->logger->info(sprintf(
            '[AI-HANDLER] analysisId=%s action=%s handlerStarted=true',
            $analysisId,
            $action
        ));

        $this->logger->info('Confirmed metadata validated', [
            'analysis_id' => $analysisId,
        ]);

        $this->logger->info('Starting Direct AI analysis', [
            'analysis_id' => $analysisId,
            'attempt_id' => $attemptId,
        ]);

        try {
            $this->directAiAnalysisService->runDirectAnalysis($analysisId, $studentId, $isRetry, $attemptId);
            
            $this->logger->info('Direct AI analysis succeeded', [
                'analysis_id' => $analysisId,
                'attempt_id' => $attemptId,
            ]);
            
            $this->logger->info('Analysis processing completed successfully', [
                'analysis_id' => $analysisId,
                'engine' => 'direct',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Direct AI analysis failed', [
                'analysis_id' => $analysisId,
                'attempt_id' => $attemptId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Check if this is an eligible provider/processing failure for fallback
            if ($this->isEligibleForFallback($e)) {
                $this->logger->info('Starting legacy analysis fallback', [
                    'analysis_id' => $analysisId,
                    'reason' => get_class($e) . ': ' . $e->getMessage(),
                ]);
                
                $this->logger->info('Using confirmed metadata for legacy fallback', [
                    'analysis_id' => $analysisId,
                ]);
                
                try {
                    $this->legacyProcessingService->processAnalysis(
                        $message->analysisId,
                        $message->studentId,
                        $isRetry,
                        $attemptId
                    );

                    $this->logger->info('Legacy fallback completed', [
                        'analysis_id' => $analysisId,
                    ]);

                    $this->enforceConfirmedMetadataLifecycle($analysisId, $studentId);
                } catch (\Throwable $legacyError) {
                    $this->logger->error('Legacy fallback also failed', [
                        'analysis_id' => $analysisId,
                        'error' => $legacyError->getMessage(),
                        'trace' => $legacyError->getTraceAsString(),
                    ]);
                    throw $legacyError;
                }
            } else {
                // Not an eligible failure - rethrow
                throw $e;
            }
        }
    }

    private function handleLegacyMode(ProcessAnalysisMessage $message): void
    {
        $this->legacyProcessingService->processAnalysis(
            $message->analysisId,
            $message->studentId,
            $message->isRetry,
            $message->attemptId
        );
    }

    /**
     * Determine if a Direct AI failure is eligible for legacy fallback.
     * Only provider/processing failures should trigger fallback.
     */
    private function isEligibleForFallback(\Throwable $e): bool
    {
        // OpenAI quota/rate-limit failures
        if ($e instanceof VisionApiException) {
            $errorType = $e->getErrorType();
            if (in_array($errorType, ['insufficient_quota', 'credit_balance_exhausted', 'rate_limit'], true)) {
                return true;
            }
        }

        // Timeout failures
        if ($e instanceof \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface) {
            return true;
        }

        // Server errors (5xx)
        if ($e instanceof \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface) {
            return true;
        }

        // Network errors
        if ($e instanceof \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface) {
            return true;
        }

        // Malformed/unusable OpenAI response
        if ($e instanceof \RuntimeException && (
            str_contains($e->getMessage(), 'malformed') ||
            str_contains($e->getMessage(), 'validation') ||
            str_contains($e->getMessage(), 'schema') ||
            str_contains($e->getMessage(), 'invalid JSON') ||
            str_contains($e->getMessage(), 'unusable')
        )) {
            return true;
        }

        return false;
    }

    private function enforceConfirmedMetadataLifecycle(string $analysisId, string $studentId): void
    {
        $mongoAnalysis = $this->mongoAnalysisRepository->findByAnalysisIdAndStudentId($analysisId, $studentId);
        if (!$mongoAnalysis) {
            return;
        }

        $processing = $mongoAnalysis->getProcessing();
        if ($processing === null) {
            return;
        }

        $currentStatus = $mongoAnalysis->getStatus();
        if ($currentStatus !== 'NEEDS_REVIEW') {
            return;
        }

        if (!$this->hasConfirmedMetadata($mongoAnalysis)) {
            return;
        }

        $aiAnalysis = $mongoAnalysis->getAiAnalysis();
        $hasAiAnalysis = is_array($aiAnalysis) && (
            !empty($aiAnalysis['currentExamAnalysis']) ||
            !empty($aiAnalysis['questionAnalysis']) ||
            !empty($aiAnalysis['questionEvaluations'])
        );

        $now = new \DateTimeImmutable();

        if ($hasAiAnalysis) {
            $processing->setStage('COMPLETED');
            $processing->setStatus('DONE');
            $processing->setCompletedAt($now);
            $mongoAnalysis->setStatus('DONE');
            $mongoAnalysis->setUpdatedAt($now);

            $this->mongoAnalysisRepository->save($mongoAnalysis);

            $pgAnalysis = $this->pgAnalysisRepository->findByAnalysisId($analysisId);
            if ($pgAnalysis !== null) {
                try {
                    $pgAnalysis->setStatus('DONE');
                    $this->pgAnalysisRepository->getEntityManager()->flush();
                } catch (\Throwable $flushError) {
                    $this->logger->error('Failed to update PostgreSQL status to DONE after confirmed fallback', [
                        'analysis_id' => $analysisId,
                        'error' => $flushError->getMessage(),
                    ]);
                }
            }

            $this->logger->info('Confirmed metadata lifecycle enforced: analysis marked DONE after fallback AI analysis', [
                'analysis_id' => $analysisId,
            ]);
        } else {
            $processing->setStage('FAILED');
            $processing->setStatus('FAILED');
            $processing->setError('AI analysis could not be completed. Please try again.');
            $processing->setCompletedAt($now);
            $mongoAnalysis->setStatus('FAILED');
            $mongoAnalysis->setUpdatedAt($now);

            $this->mongoAnalysisRepository->save($mongoAnalysis);

            $pgAnalysis = $this->pgAnalysisRepository->findByAnalysisId($analysisId);
            if ($pgAnalysis !== null) {
                try {
                    $pgAnalysis->setStatus('FAILED');
                    $this->pgAnalysisRepository->getEntityManager()->flush();
                } catch (\Throwable $flushError) {
                    $this->logger->error('Failed to update PostgreSQL status to FAILED after confirmed fallback', [
                        'analysis_id' => $analysisId,
                        'error' => $flushError->getMessage(),
                    ]);
                }
            }

            $this->logger->warning('Confirmed metadata lifecycle enforced: analysis marked FAILED - fallback produced no AI analysis', [
                'analysis_id' => $analysisId,
            ]);
        }
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

    private function markAnalysisAsFailed(string $analysisId, string $studentId, \Throwable $e): void
    {
        $mongoAnalysis = $this->mongoAnalysisRepository->findByAnalysisIdAndStudentId($analysisId, $studentId);
        if (!$mongoAnalysis) {
            return;
        }

        $processing = $mongoAnalysis->getProcessing();
        if ($processing === null) {
            return;
        }

        $safeMessage = 'AI analysis could not be completed. Please try again.';
        $now = new \DateTimeImmutable();

        $processing->setStage('FAILED');
        $processing->setStatus('FAILED');
        $processing->setError($safeMessage);
        $processing->setCompletedAt($now);

        $mongoAnalysis->setStatus('FAILED');
        $mongoAnalysis->setUpdatedAt($now);

        $this->mongoAnalysisRepository->save($mongoAnalysis);

        $pgAnalysis = $this->pgAnalysisRepository->findByAnalysisId($analysisId);
        if ($pgAnalysis !== null) {
            try {
                $pgAnalysis->setStatus('FAILED');
                $this->pgAnalysisRepository->getEntityManager()->flush();
            } catch (\Throwable $flushError) {
                $this->logger->error('Failed to update PostgreSQL status on analysis failure', [
                    'analysis_id' => $analysisId,
                    'error' => $flushError->getMessage(),
                ]);
            }
        }

        $this->logger->info('Analysis marked as FAILED after processing error', [
            'analysis_id' => $analysisId,
            'student_id' => $studentId,
            'error' => $e->getMessage(),
        ]);
    }
}