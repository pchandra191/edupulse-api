<?php

declare(strict_types=1);

namespace App\Service\Analysis;

use App\Document\Analysis;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use Psr\Log\LoggerInterface;

readonly class AnalysisProcessingRecoveryService
{
    public const STALE_PROCESSING_THRESHOLD = 180;

    public function __construct(
        private MongoAnalysisRepository $mongoAnalysisRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function recoverStaleProcessing(int $thresholdSeconds = self::STALE_PROCESSING_THRESHOLD): int
    {
        $staleAnalyses = $this->mongoAnalysisRepository->findStaleProcessing($thresholdSeconds);

        if (count($staleAnalyses) === 0) {
            $this->logger->info('No stale PROCESSING analyses found for recovery', [
                'threshold_seconds' => $thresholdSeconds,
            ]);
            return 0;
        }

        $recoveredCount = 0;

        foreach ($staleAnalyses as $analysis) {
            try {
                $this->recoverAnalysis($analysis);
                $recoveredCount++;

                $this->logger->info('Stale analysis recovered', [
                    'analysis_id' => $analysis->getAnalysisId(),
                    'started_at' => $analysis->getProcessing()->getStartedAt() ? $analysis->getProcessing()->getStartedAt()->format(\DateTimeInterface::ATOM) : null,
                    'attempt_id' => $analysis->getProcessing()->getAttemptId(),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to recover stale analysis', [
                    'analysis_id' => $analysis->getAnalysisId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $recoveredCount;
    }

    public function recoverIfStale(string $analysisId, int $thresholdSeconds = self::STALE_PROCESSING_THRESHOLD): bool
    {
        $analysis = $this->mongoAnalysisRepository->findByAnalysisId($analysisId);

        if (!$analysis) {
            return false;
        }

        if ($analysis->getStatus() !== 'PROCESSING') {
            return false;
        }

        $processing = $analysis->getProcessing();
        if ($processing->getStatus() !== 'PROCESSING') {
            return false;
        }

        $startedAt = $processing->getStartedAt();
        if ($startedAt === null) {
            return false;
        }

        $elapsed = (new \DateTimeImmutable())->getTimestamp() - $startedAt->getTimestamp();

        if ($elapsed < $thresholdSeconds) {
            $this->logger->debug('Analysis is still actively processing, not recovering', [
                'analysis_id' => $analysisId,
                'elapsed_seconds' => $elapsed,
                'threshold_seconds' => $thresholdSeconds,
            ]);
            return false;
        }

        $this->recoverAnalysis($analysis);

        $this->logger->info('Stale analysis recovered before processing', [
            'analysis_id' => $analysisId,
            'elapsed_seconds' => $elapsed,
            'threshold_seconds' => $thresholdSeconds,
        ]);

        return true;
    }

    private function recoverAnalysis(Analysis $analysis): void
    {
        $processing = $analysis->getProcessing();

        $processing->setStage('TIMEOUT');
        $processing->setStatus('FAILED');
        $processing->setError('Analysis processing stopped unexpectedly. The processing attempt timed out or the worker stopped. Please try again.');
        $processing->setCompletedAt(new \DateTimeImmutable());

        $analysis->setStatus('FAILED');
        $analysis->setUpdatedAt(new \DateTimeImmutable());

        $this->mongoAnalysisRepository->save($analysis);
    }
}
