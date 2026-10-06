<?php

declare(strict_types=1);

namespace App\Tests\Exception;

use App\Exception\VisionApiException;
use PHPUnit\Framework\TestCase;

class VisionApiExceptionTest extends TestCase
{
    public function testIsRetryableReturnsFalseForInvalidRequest(): void
    {
        $exception = new VisionApiException('Invalid request', 'unsupported_parameter', 400);
        $this->assertFalse($exception->isRetryable());
    }

    public function testIsRetryableReturnsFalseForEncodingError(): void
    {
        $exception = new VisionApiException('Control character error', 'encoding_error', 400);
        $this->assertFalse($exception->isRetryable());
    }

    public function testIsRetryableReturnsFalseForInvalidRequestError(): void
    {
        $exception = new VisionApiException('Invalid request', 'invalid_request_error', 400);
        $this->assertFalse($exception->isRetryable());
    }

    public function testIsRetryableReturnsFalseForInsufficientQuota(): void
    {
        $exception = new VisionApiException('Quota exceeded', 'insufficient_quota', 429);
        $this->assertFalse($exception->isRetryable());
    }

    public function testIsRetryableReturnsFalseForCreditBalanceExhausted(): void
    {
        $exception = new VisionApiException('Credit exhausted', 'credit_balance_exhausted', 429);
        $this->assertFalse($exception->isRetryable());
    }

    public function testIsRetryableReturnsTrueForRateLimit(): void
    {
        $exception = new VisionApiException('Rate limit', 'rate_limit', 429);
        $this->assertTrue($exception->isRetryable());
    }

    public function testIsRetryableReturnsTrueForServerError(): void
    {
        $exception = new VisionApiException('Server error', 'server_error', 500);
        $this->assertTrue($exception->isRetryable());
    }

    public function testIsRetryableReturnsTrueForTransportError(): void
    {
        $exception = new VisionApiException('Connection failed', null, 0);
        $this->assertTrue($exception->isRetryable());
    }

    public function testIsRetryableReturnsTrueFor5xxStatusCode(): void
    {
        $exception = new VisionApiException('Server error', null, 503);
        $this->assertTrue($exception->isRetryable());
    }

    public function testIsRetryableReturnsTrueForRateLimit429(): void
    {
        $exception = new VisionApiException('Rate limit', 'rate_limit', 429);
        $this->assertTrue($exception->isRetryable());
    }

    public function testIsRetryableReturnsFalseForUnknownError(): void
    {
        $exception = new VisionApiException('Unknown error', 'unknown_type', 400);
        $this->assertFalse($exception->isRetryable());
    }

    public function testIsRetryableReturnsFalseForEncodingErrorInMessage(): void
    {
        $exception = new VisionApiException('Control character error, possibly incorrectly encoded', null, 400);
        $this->assertFalse($exception->isRetryable());
    }

    public function testGetErrorType(): void
    {
        $exception = new VisionApiException('Test', 'unsupported_parameter', 400);
        $this->assertSame('unsupported_parameter', $exception->getErrorType());
    }

    public function testGetStatusCode(): void
    {
        $exception = new VisionApiException('Test', 'unsupported_parameter', 400);
        $this->assertSame(400, $exception->getStatusCode());
    }

    public function testIsInsufficientQuota(): void
    {
        $exception = new VisionApiException('Quota', 'insufficient_quota', 429);
        $this->assertTrue($exception->isInsufficientQuota());
        $this->assertFalse($exception->isCreditBalanceExhausted());
    }

    public function testIsCreditBalanceExhausted(): void
    {
        $exception = new VisionApiException('Exhausted', 'credit_balance_exhausted', 429);
        $this->assertTrue($exception->isCreditBalanceExhausted());
        $this->assertFalse($exception->isInsufficientQuota());
    }

    public function testIsRateLimit(): void
    {
        $exception = new VisionApiException('Rate limit', 'rate_limit', 429);
        $this->assertTrue($exception->isRateLimit());
        $this->assertFalse($exception->isInsufficientQuota());
    }

    public function testIsServerError(): void
    {
        $exception = new VisionApiException('Server error', 'server_error', 500);
        $this->assertTrue($exception->isServerError());
    }

    public function testIsInvalidRequest(): void
    {
        $exception = new VisionApiException('Invalid', 'unsupported_parameter', 400);
        $this->assertTrue($exception->isInvalidRequest());
    }

    public function testIsEncodingError(): void
    {
        $exception = new VisionApiException('Control character error', 'encoding_error', 400);
        $this->assertTrue($exception->isEncodingError());
    }

    public function testIsEncodingErrorFromMessage(): void
    {
        $exception = new VisionApiException('Control character error, possibly incorrectly encoded', null, 400);
        $this->assertTrue($exception->isEncodingError());
    }

    public function testStaticInsufficientQuota(): void
    {
        $exception = VisionApiException::insufficientQuota('Quota exceeded');
        $this->assertSame('insufficient_quota', $exception->getErrorType());
        $this->assertTrue($exception->isInsufficientQuota());
    }

    public function testStaticCreditBalanceExhausted(): void
    {
        $exception = VisionApiException::creditBalanceExhausted('Credit exhausted');
        $this->assertSame('credit_balance_exhausted', $exception->getErrorType());
        $this->assertTrue($exception->isCreditBalanceExhausted());
    }

    public function testStaticRateLimit(): void
    {
        $exception = VisionApiException::rateLimit('Rate limit');
        $this->assertSame('rate_limit', $exception->getErrorType());
        $this->assertTrue($exception->isRateLimit());
    }

    public function testStaticServerError(): void
    {
        $exception = VisionApiException::serverError('Server error');
        $this->assertSame('server_error', $exception->getErrorType());
        $this->assertTrue($exception->isServerError());
    }

    public function testFromHttp429(): void
    {
        $responseBody = '{"error": {"type": "rate_limit", "message": "Rate limit exceeded"}}';
        $exception = VisionApiException::fromHttp429(429, $responseBody);
        
        $this->assertSame('rate_limit', $exception->getErrorType());
        $this->assertSame(429, $exception->getStatusCode());
        $this->assertTrue($exception->isRetryable());
    }

    public function testIsRetryableReturnsFalseForAuthenticationError401(): void
    {
        $exception = new VisionApiException('Invalid API key', 'invalid_api_key', 401);
        $this->assertFalse($exception->isRetryable());
    }

    public function testIsRetryableReturnsFalseForAuthenticationError401ViaMessage(): void
    {
        $exception = new VisionApiException('Invalid API key', null, 401);
        $this->assertFalse($exception->isRetryable());
    }

    public function testIsRetryableReturnsFalseForAuthenticationError401ViaType(): void
    {
        $exception = new VisionApiException('Authentication error', 'authentication_error', 401);
        $this->assertFalse($exception->isRetryable());
    }

    public function testIsRetryableReturnsFalseForInvalidApiKeyType(): void
    {
        $exception = new VisionApiException('Invalid API key', 'invalid_api_key', 401);
        $this->assertFalse($exception->isRetryable());
    }
}