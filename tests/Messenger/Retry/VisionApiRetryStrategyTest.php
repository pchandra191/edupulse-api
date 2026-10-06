<?php

declare(strict_types=1);

namespace App\Tests\Messenger\Retry;

use App\Exception\VisionApiException;
use App\Messenger\Retry\VisionApiRetryStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

class VisionApiRetryStrategyTest extends TestCase
{
    private VisionApiRetryStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new VisionApiRetryStrategy(3, 2, 1000);
    }

private function createEnvelope(int $retryCount = 0): Envelope
    {
        $envelope = new Envelope(new \stdClass());
        if ($retryCount > 0) {
            $envelope = $envelope->with(new \Symfony\Component\Messenger\Stamp\RedeliveryStamp($retryCount));
        }
        return $envelope;
    }

    public function testIsRetryableReturnsTrueForNullThrowable(): void
    {
        $envelope = $this->createEnvelope();
        $result = $this->strategy->isRetryable($envelope, null);
        $this->assertTrue($result);
    }

    public function testIsRetryableReturnsTrueForUnknownException(): void
    {
        $envelope = $this->createEnvelope();
        $result = $this->strategy->isRetryable($envelope, new \RuntimeException('Unknown error'));
        $this->assertTrue($result);
    }

    public function testIsRetryableReturnsFalseForInvalidRequest(): void
    {
        $envelope = $this->createEnvelope();
        $exception = new \App\Exception\VisionApiException('Invalid request', 'unsupported_parameter', 400);
        $result = $this->strategy->isRetryable($envelope, $exception);
        $this->assertFalse($result);
    }

    public function testIsRetryableReturnsFalseForEncodingError(): void
    {
        $envelope = $this->createEnvelope();
        $exception = new \App\Exception\VisionApiException('Control character error', 'encoding_error', 400);
        $result = $this->strategy->isRetryable($envelope, $exception);
        $this->assertFalse($result);
    }

    public function testIsRetryableReturnsFalseForInsufficientQuota(): void
    {
        $envelope = $this->createEnvelope();
        $exception = new \App\Exception\VisionApiException('Quota exceeded', 'insufficient_quota', 429);
        $result = $this->strategy->isRetryable($envelope, $exception);
        $this->assertFalse($result);
    }

    public function testIsRetryableReturnsFalseForCreditBalanceExhausted(): void
    {
        $envelope = $this->createEnvelope();
        $exception = new \App\Exception\VisionApiException('Credit exhausted', 'credit_balance_exhausted', 429);
        $result = $this->strategy->isRetryable($envelope, $exception);
        $this->assertFalse($result);
    }

    public function testIsRetryableReturnsTrueForRateLimit(): void
    {
        $envelope = $this->createEnvelope();
        $exception = new \App\Exception\VisionApiException('Rate limit', 'rate_limit', 429);
        $result = $this->strategy->isRetryable($envelope, $exception);
        $this->assertTrue($result);
    }

    public function testIsRetryableReturnsTrueForServerError(): void
    {
        $envelope = $this->createEnvelope();
        $exception = new \App\Exception\VisionApiException('Server error', 'server_error', 500);
        $result = $this->strategy->isRetryable($envelope, $exception);
        $this->assertTrue($result);
    }

    public function testIsRetryableReturnsTrueForTransportError(): void
    {
        $envelope = $this->createEnvelope();
        $exception = new \App\Exception\VisionApiException('Connection failed', null, 0);
        $result = $this->strategy->isRetryable($envelope, $exception);
        $this->assertTrue($result);
    }

    public function testIsRetryableReturnsTrueFor5xxStatusCode(): void
    {
        $envelope = $this->createEnvelope();
        $exception = new \App\Exception\VisionApiException('Server error', null, 503);
        $result = $this->strategy->isRetryable($envelope, $exception);
        $this->assertTrue($result);
    }

    public function testIsRetryableReturnsTrueForRateLimit429(): void
    {
        $envelope = $this->createEnvelope();
        $exception = new \App\Exception\VisionApiException('Rate limit', 'rate_limit', 429);
        $result = $this->strategy->isRetryable($envelope, $exception);
        $this->assertTrue($result);
    }

    public function testIsRetryableReturnsFalseForUnknownError(): void
    {
        $envelope = $this->createEnvelope();
        $exception = new \App\Exception\VisionApiException('Unknown error', 'unknown_type', 400);
        $result = $this->strategy->isRetryable($envelope, $exception);
        $this->assertFalse($result);
    }

    public function testIsRetryableReturnsFalseForEncodingErrorInMessage(): void
    {
        $envelope = $this->createEnvelope();
        $exception = new \App\Exception\VisionApiException('Control character error, possibly incorrectly encoded', null, 400);
        $result = $this->strategy->isRetryable($envelope, $exception);
        $this->assertFalse($result);
    }

    public function testGetWaitingTimeReturnsZeroForNonRetryableException(): void
    {
        $envelope = $this->createEnvelope();
        $exception = new \App\Exception\VisionApiException('Invalid request', 'unsupported_parameter', 400);
        $waitingTime = $this->strategy->getWaitingTime($this->createEnvelope(), $exception);
        $this->assertSame(0, $waitingTime);
    }

    public function testGetWaitingTimeReturnsZeroForInsufficientQuota(): void
    {
        $envelope = $this->createEnvelope();
        $exception = new \App\Exception\VisionApiException('Quota exceeded', 'insufficient_quota', 429);
        $waitingTime = $this->strategy->getWaitingTime($this->createEnvelope(), $exception);
        $this->assertSame(0, $waitingTime);
    }

    public function testGetWaitingTimeReturnsPositiveForRateLimit(): void
    {
        $envelope = $this->createEnvelope();
        $exception = new \App\Exception\VisionApiException('Rate limit', 'rate_limit', 429);
        $waitingTime = $this->strategy->getWaitingTime($this->createEnvelope(), $exception);
        $this->assertGreaterThan(0, $waitingTime);
    }

    public function testGetWaitingTimeReturnsZeroForMaxRetriesExceeded(): void
    {
        $envelope = $this->createEnvelope(3); // maxRetries is 3
        $exception = new \App\Exception\VisionApiException('Rate limit', 'rate_limit', 429);
        $waitingTime = $this->strategy->getWaitingTime($envelope, $exception);
        $this->assertSame(0, $waitingTime);
    }

    public function testGetWaitingTimeReturnsPositiveForServerError(): void
    {
        $envelope = $this->createEnvelope();
        $exception = new \App\Exception\VisionApiException('Server error', 'server_error', 500);
        $waitingTime = $this->strategy->getWaitingTime($this->createEnvelope(), $exception);
        $this->assertGreaterThan(0, $waitingTime);
    }

    public function testGetWaitingTimeReturnsPositiveForTransportError(): void
    {
        $envelope = $this->createEnvelope();
        $exception = new \App\Exception\VisionApiException('Connection failed', null, 0);
        $waitingTime = $this->strategy->getWaitingTime($this->createEnvelope(), $exception);
        $this->assertGreaterThan(0, $waitingTime);
    }

    public function testGetWaitingTimeExponentialBackoff(): void
    {
        $strategy = new VisionApiRetryStrategy(3, 2, 1000);
        $exception = new \App\Exception\VisionApiException('Rate limit', 'rate_limit', 429);
        
        // First retry (retryCount = 0): delay = 1000 * 2^0 = 1000
        $waitingTime1 = $strategy->getWaitingTime($this->createEnvelope(0), $exception);
        $this->assertSame(1000, $waitingTime1);
        
        // Second retry (retryCount = 1): delay = 1000 * 2^1 = 2000
        $waitingTime2 = $strategy->getWaitingTime($this->createEnvelope(1), $exception);
        $this->assertSame(2000, $waitingTime2);
        
        // Third retry (retryCount = 2): delay = 1000 * 2^2 = 4000
        $waitingTime3 = $strategy->getWaitingTime($this->createEnvelope(2), $exception);
        $this->assertSame(4000, $waitingTime3);
    }

    public function testGetWaitingTimeRespectsMaxRetries(): void
    {
        $strategy = new VisionApiRetryStrategy(2, 2, 1000);
        $exception = new \App\Exception\VisionApiException('Rate limit', 'rate_limit', 429);
        
        // First retry (retryCount = 0): delay = 1000
        $waitingTime1 = $strategy->getWaitingTime($this->createEnvelope(0), $exception);
        $this->assertSame(1000, $waitingTime1);
        
        // Second retry (retryCount = 1): delay = 2000
        $waitingTime2 = $strategy->getWaitingTime($this->createEnvelope(1), $exception);
        $this->assertSame(2000, $waitingTime2);
        
        // Third retry (retryCount = 2): exceeds maxRetries (2), should return 0
        $waitingTime3 = $strategy->getWaitingTime($this->createEnvelope(2), $exception);
        $this->assertSame(0, $waitingTime3);
    }

    public function testGetWaitingTimeReturnsZeroForNullThrowable(): void
    {
        $waitingTime = $this->strategy->getWaitingTime($this->createEnvelope(), null);
        $this->assertSame(0, $waitingTime);
    }
}