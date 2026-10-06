<?php

declare(strict_types=1);

namespace App\Exception;

class VisionApiException extends \RuntimeException
{
    private ?string $errorType;
    private int $statusCode;

    public function __construct(string $message, ?string $errorType = null, int $statusCode = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errorType = $errorType;
        $this->statusCode = $statusCode;
    }

    public function getErrorType(): ?string
    {
        return $this->errorType;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function isInsufficientQuota(): bool
    {
        return $this->errorType === 'insufficient_quota';
    }

    public function isCreditBalanceExhausted(): bool
    {
        return $this->errorType === 'credit_balance_exhausted';
    }

    public function isRateLimit(): bool
    {
        return $this->errorType === 'rate_limit';
    }

    public function isServerError(): bool
    {
        return $this->errorType === 'server_error';
    }

    public function isInvalidRequest(): bool
    {
        return in_array($this->errorType, [
            'invalid_request_error',
            'invalid_parameter',
            'invalid_request',
            'unsupported_parameter',
            'malformed_request',
        ], true);
    }

    public function isAuthenticationError(): bool
    {
        return $this->errorType === 'invalid_api_key' ||
               $this->errorType === 'authentication_error' ||
               $this->statusCode === 401;
    }

    public function isEncodingError(): bool
    {
        return $this->errorType === 'encoding_error' ||
               str_contains($this->getMessage(), 'control character') ||
               str_contains($this->getMessage(), 'incorrectly encoded');
    }

    public function isJsonParseError(): bool
    {
        return $this->errorType === 'json_parse_error' ||
               str_contains($this->getMessage(), 'Syntax error') ||
               str_contains($this->getMessage(), 'JSON') ||
               str_contains($this->getMessage(), 'malformed') ||
               str_contains($this->getMessage(), 'incomplete');
    }

    /**
     * Determines if this error should trigger a retry.
     * Non-retryable errors:
     * - 400 Bad Request (invalid request, unsupported parameter, encoding errors)
     * - 429 with insufficient_quota or credit_balance_exhausted
     * - JSON parse errors (malformed/incomplete JSON)
     * 
     * Retryable errors:
     * - 429 rate_limit (temporary)
     * - 5xx server errors
     * - Network/connection timeouts
     */
    public function isRetryable(): bool
    {
        // Non-retryable: invalid request parameters
        if ($this->isInvalidRequest() || $this->isEncodingError() || $this->isJsonParseError()) {
            return false;
        }

        // Non-retryable: quota/credit exhaustion
        if ($this->isInsufficientQuota() || $this->isCreditBalanceExhausted()) {
            return false;
        }

        // Non-retryable: authentication/authorization errors (401)
        if ($this->isAuthenticationError()) {
            return false;
        }

        // Retryable: rate limit (temporary)
        if ($this->isRateLimit()) {
            return true;
        }

        // Retryable: server errors (5xx)
        if ($this->isServerError()) {
            return true;
        }

        // Retryable: network/connection issues (status code 0 or transport errors)
        if ($this->statusCode === 0) {
            return true;
        }

        // Retryable: 5xx status codes
        if ($this->statusCode >= 500) {
            return true;
        }

        // Default: non-retryable for unknown errors
        return false;
    }

    public static function insufficientQuota(string $message): self
    {
        return new self($message, 'insufficient_quota');
    }

    public static function creditBalanceExhausted(string $message): self
    {
        return new self($message, 'credit_balance_exhausted');
    }

    public static function rateLimit(string $message): self
    {
        return new self($message, 'rate_limit');
    }

    public static function serverError(string $message): self
    {
        return new self($message, 'server_error');
    }

    public static function jsonParseError(string $message): self
    {
        return new self($message, 'json_parse_error', 0);
    }

    public static function fromHttp429(int $statusCode, string $responseBody): self
    {
        $errorType = null;

        try {
            $decoded = json_decode($responseBody, true);
            if (is_array($decoded)) {
                $errorType = $decoded['error']['type'] ?? null;
            }
        } catch (\Throwable) {
        }

        $message = "Vision API returned status {$statusCode}";
        if ($errorType !== null) {
            $message .= ": {$errorType}";
        }

        return new self($message, $errorType, $statusCode);
    }
}
