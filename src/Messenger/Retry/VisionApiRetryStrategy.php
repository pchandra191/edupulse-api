<?php

declare(strict_types=1);

namespace App\Messenger\Retry;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use App\Exception\VisionApiException;
use App\Exception\AnalysisLifecycleException;

/**
 * Custom retry strategy that respects VisionApiException::isRetryable()
 * and does not retry on lifecycle errors (AnalysisLifecycleException).
 */
class VisionApiRetryStrategy implements RetryStrategyInterface
{
    private int $maxRetries;
    private int $multiplier;
    private int $delay;

    public function __construct(int $maxRetries = 3, int $multiplier = 2, int $delay = 1000)
    {
        $this->maxRetries = $maxRetries;
        $this->multiplier = $multiplier;
        $this->delay = $delay;
    }

    public function isRetryable(Envelope $message, ?\Throwable $throwable = null): bool
    {
        if ($throwable === null) {
            return true;
        }

        // Check if it's a VisionApiException and if it's retryable
        $visionApiException = $this->findVisionApiException($throwable);
        
        if ($visionApiException !== null) {
            return $visionApiException->isRetryable();
        }

        // Check for lifecycle errors - these are deterministic and should not be retried
        $lifecycleException = $this->findLifecycleException($throwable);
        if ($lifecycleException !== null) {
            return false;
        }

        // For other exceptions, allow retry (default behavior)
        return true;
    }

    public function getWaitingTime(Envelope $message, ?\Throwable $throwable = null): int
    {
        if ($throwable === null) {
            return 0;
        }

        // Check if it's a VisionApiException and if it's retryable
        $visionApiException = $this->findVisionApiException($throwable);
        
        if ($visionApiException !== null) {
            if (!$visionApiException->isRetryable()) {
                // Non-retryable error - return 0 to indicate no retry
                return 0;
            }
        }

        // Check for lifecycle errors - these are deterministic and should not be retried
        $lifecycleException = $this->findLifecycleException($throwable);
        if ($lifecycleException !== null) {
            return 0;
        }

        // For other exceptions, use default retry logic with exponential backoff
        $retryCount = \Symfony\Component\Messenger\Stamp\RedeliveryStamp::getRetryCountFromEnvelope($message);
        
        if ($retryCount >= $this->maxRetries) {
            return 0;
        }

        return $this->delay * pow($this->multiplier, $retryCount);
    }

    /**
     * Find VisionApiException in the exception chain
     */
    private function findVisionApiException(\Throwable $throwable): ?\App\Exception\VisionApiException
    {
        if ($throwable instanceof \App\Exception\VisionApiException) {
            return $throwable;
        }

        $previous = $throwable->getPrevious();
        while ($previous !== null) {
            if ($previous instanceof \App\Exception\VisionApiException) {
                return $previous;
            }
            $previous = $previous->getPrevious();
        }

        return null;
    }

    /**
     * Find AnalysisLifecycleException in the exception chain
     */
    private function findLifecycleException(\Throwable $throwable): ?\App\Exception\AnalysisLifecycleException
    {
        if ($throwable instanceof \App\Exception\AnalysisLifecycleException) {
            return $throwable;
        }

        $previous = $throwable->getPrevious();
        while ($previous !== null) {
            if ($previous instanceof \App\Exception\AnalysisLifecycleException) {
                return $previous;
            }
            $previous = $previous->getPrevious();
        }

        return null;
    }
}