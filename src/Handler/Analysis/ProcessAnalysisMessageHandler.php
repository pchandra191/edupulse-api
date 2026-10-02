<?php
declare(strict_types=1);

namespace App\Handler\Analysis;

use App\Message\Analysis\ProcessAnalysisMessage;
use App\Service\Analysis\AnalysisProcessingRecoveryService;
use App\Service\Analysis\AnalysisProcessingService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class ProcessAnalysisMessageHandler
{
    public function __construct(
        private AnalysisProcessingService $processingService,
        private AnalysisProcessingRecoveryService $recoveryService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessAnalysisMessage $message): void
    {
        $this->logger->info('Starting analysis processing', [
            'analysis_id' => $message->analysisId,
            'student_id' => $message->studentId,
            'is_retry' => $message->isRetry,
            'attempt_id' => $message->attemptId,
        ]);

        try {
            $this->recoveryService->recoverIfStale($message->analysisId);

            $this->processingService->processAnalysis(
                $message->analysisId,
                $message->studentId,
                $message->isRetry,
                $message->attemptId
            );

            $this->logger->info('Analysis processing completed successfully', [
                'analysis_id' => $message->analysisId,
                'attempt_id' => $message->attemptId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Analysis processing failed', [
                'analysis_id' => $message->analysisId,
                'student_id' => $message->studentId,
                'attempt_id' => $message->attemptId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}