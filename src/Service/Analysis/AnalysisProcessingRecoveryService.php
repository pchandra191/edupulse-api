<?php

declare(strict_types=1);

namespace App\Service\Analysis;

use App\Document\Analysis;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

readonly class AnalysisProcessingRecoveryService
{
    public const STALE_PROCESSING_THRESHOLD = 300;

    public function __construct(
        private MongoAnalysisRepository $mongoAnalysisRepository,
        private DocumentManager $documentManager,
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
                if ($this->recoverAnalysisIfStillStale($analysis, $thresholdSeconds)) {
                    $recoveredCount++;

                    $this->logger->info('Stale analysis recovered', [
                        'analysis_id' => $analysis->getAnalysisId(),
                        'started_at' => $analysis->getProcessing()->getStartedAt() ? $analysis->getProcessing()->getStartedAt()->format(\DateTimeInterface::ATOM) : null,
                        'attempt_id' => $analysis->getProcessing()->getAttemptId(),
                    ]);
                }
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

        return $this->recoverAnalysisIfStillStale($analysis, $thresholdSeconds);
    }

    private function recoverAnalysisIfStillStale(Analysis $analysis, int $thresholdSeconds): bool
    {
        $analysisId = $analysis->getAnalysisId();
        $startedAt = $analysis->getProcessing()->getStartedAt();

        if ($startedAt === null) {
            return false;
        }

        // Double-check elapsed time at the moment of recovery
        $elapsed = (new \DateTimeImmutable())->getTimestamp() - $startedAt->getTimestamp();
        if ($elapsed < $thresholdSeconds) {
            return false;
        }

        // Use atomic findAndUpdate to prevent race conditions
        $analysisId = $analysis->getAnalysisId();
        $startedAt = $analysis->getProcessing()->getStartedAt();

        if ($startedAt === null) {
            return false;
        }

        $qb = $this->documentManager->createQueryBuilder(Analysis::class)
            ->findAndUpdate()
            ->field('analysisId')->equals($analysisId)
            ->field('status')->equals('PROCESSING')
            ->field('processing.status')->equals('PROCESSING')
            ->field('processing.startedAt')->equals($startedAt)
            ->field('processing.stage')->set('TIMEOUT')
            ->field('processing.status')->set('FAILED')
            ->field('processing.error')->set('Analysis processing timed out after 5 minutes.')
            ->field('processing.completedAt')->set(new \DateTimeImmutable())
            ->field('status')->set('FAILED')
            ->field('updatedAt')->set(new \DateTimeImmutable());

        $result = $qb->getQuery()->execute();

        // If the update didn't affect any document, it means the analysis was already modified by another process
        if (!$result) {
            $this->logger->info('Analysis was already modified by another process, skipping recovery', [
                'analysis_id' => $analysisId,
            ]);
            return false;
        }

        $this->logger->info('Stale analysis atomically recovered', [
            'analysis_id' => $analysisId,
        ]);

        return true;
    }
}