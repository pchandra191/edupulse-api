<?php

declare(strict_types=1);

namespace App\Tests\Exception;

use App\Exception\VisionApiException;
use PHPUnit\Framework\TestCase;

class VisionApiExceptionTest extends TestCase
{
    public function testIsInsufficientQuotaReturnsTrueForInsufficientQuota(): void
    {
        $exception = new VisionApiException('insufficient quota', 'insufficient_quota');

        $this->assertTrue($exception->isInsufficientQuota());
        $this->assertFalse($exception->isRateLimit());
        $this->assertSame('insufficient_quota', $exception->getErrorType());
    }

    public function testIsRateLimitReturnsTrueForRateLimit(): void
    {
        $exception = new VisionApiException('rate limited', 'rate_limit');

        $this->assertTrue($exception->isRateLimit());
        $this->assertFalse($exception->isInsufficientQuota());
        $this->assertSame('rate_limit', $exception->getErrorType());
    }

    public function testGenericErrorReturnsFalseForAllChecks(): void
    {
        $exception = new VisionApiException('some error', 'server_error');

        $this->assertFalse($exception->isInsufficientQuota());
        $this->assertFalse($exception->isRateLimit());
        $this->assertSame('server_error', $exception->getErrorType());
    }

    public function testNullErrorTypeReturnsFalseForAllChecks(): void
    {
        $exception = new VisionApiException('some error', null);

        $this->assertFalse($exception->isInsufficientQuota());
        $this->assertFalse($exception->isRateLimit());
        $this->assertNull($exception->getErrorType());
    }

    public function testFromHttp429InsufficientQuota(): void
    {
        $exception = VisionApiException::fromHttp429(429, '{"error":{"type":"insufficient_quota","message":"quota exceeded"}}');

        $this->assertTrue($exception->isInsufficientQuota());
        $this->assertFalse($exception->isRateLimit());
        $this->assertStringContainsString('429', $exception->getMessage());
    }

    public function testFromHttp429RateLimit(): void
    {
        $exception = VisionApiException::fromHttp429(429, '{"error":{"type":"rate_limit","message":"too many requests"}}');

        $this->assertTrue($exception->isRateLimit());
        $this->assertFalse($exception->isInsufficientQuota());
        $this->assertStringContainsString('429', $exception->getMessage());
    }

    public function testFromHttp429WithUnknownType(): void
    {
        $exception = VisionApiException::fromHttp429(429, '{"error":{"type":"unknown_error"}}');

        $this->assertFalse($exception->isInsufficientQuota());
        $this->assertFalse($exception->isRateLimit());
        $this->assertSame('unknown_error', $exception->getErrorType());
    }

    public function testPreviousExceptionIsPreserved(): void
    {
        $previous = new \RuntimeException('underlying error');
        $exception = new VisionApiException('wrapper', 'server_error', $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testIsRetryableReturnsTrueForRateLimitOnly(): void
    {
        $rateLimited = new VisionApiException('rate limited', 'rate_limit');
        $this->assertTrue($rateLimited->isRetryable());

        $insufficientQuota = new VisionApiException('quota', 'insufficient_quota');
        $this->assertFalse($insufficientQuota->isRetryable());

        $serverError = new VisionApiException('server error', 'server_error');
        $this->assertTrue($serverError->isRetryable());
    }

    public function testIsServerErrorReturnsTrueForServerError(): void
    {
        $exception = new VisionApiException('server error', 'server_error');

        $this->assertTrue($exception->isServerError());
        $this->assertFalse($exception->isInsufficientQuota());
        $this->assertFalse($exception->isRateLimit());
        $this->assertSame('server_error', $exception->getErrorType());
    }

    public function testFactoryMethodsCreateCorrectExceptionTypes(): void
    {
        $quotaException = VisionApiException::insufficientQuota('quota exceeded');
        $this->assertTrue($quotaException->isInsufficientQuota());
        $this->assertFalse($quotaException->isRateLimit());
        $this->assertSame('insufficient_quota', $quotaException->getErrorType());

        $rateLimitException = VisionApiException::rateLimit('too many requests');
        $this->assertTrue($rateLimitException->isRateLimit());
        $this->assertFalse($rateLimitException->isInsufficientQuota());
        $this->assertSame('rate_limit', $rateLimitException->getErrorType());

        $serverException = VisionApiException::serverError('internal error');
        $this->assertTrue($serverException->isServerError());
        $this->assertSame('server_error', $serverException->getErrorType());
    }
}