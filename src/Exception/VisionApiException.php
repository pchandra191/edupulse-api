<?php

declare(strict_types=1);

namespace App\Exception;

class VisionApiException extends \RuntimeException
{
    private ?string $errorType;

    public function __construct(string $message, ?string $errorType = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errorType = $errorType;
    }

    public function getErrorType(): ?string
    {
        return $this->errorType;
    }

    public function isInsufficientQuota(): bool
    {
        return $this->errorType === 'insufficient_quota';
    }

    public function isRateLimit(): bool
    {
        return $this->errorType === 'rate_limit';
    }

    public function isServerError(): bool
    {
        return $this->errorType === 'server_error';
    }

    public function isRetryable(): bool
    {
        return !$this->isInsufficientQuota();
    }

    public static function insufficientQuota(string $message): self
    {
        return new self($message, 'insufficient_quota');
    }

    public static function rateLimit(string $message): self
    {
        return new self($message, 'rate_limit');
    }

    public static function serverError(string $message): self
    {
        return new self($message, 'server_error');
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

        return new self($message, $errorType);
    }
}
