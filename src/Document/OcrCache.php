<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/**
 * Cache for OCR results per page to avoid duplicate paid API calls.
 * 
 * Key: fileHash + pageNumber + provider + model + version
 */
#[ODM\Document(collection: 'ocr_cache', repositoryClass: \App\Repository\MongoDB\OcrCacheRepository::class)]
#[ODM\Index(keys: ['cacheKey' => 'asc'], unique: true)]
#[ODM\Index(keys: ['fileHash' => 'asc'])]
#[ODM\Index(keys: ['status' => 'asc'])]
#[ODM\Index(keys: ['createdAt' => 'desc'])]
class OcrCache
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    private string $id;

    #[ODM\Field(type: 'string')]
    private string $cacheKey;

    #[ODM\Field(type: 'string')]
    private string $fileHash;

    #[ODM\Field(type: 'int')]
    private int $pageNumber;

    #[ODM\Field(type: 'string')]
    private string $provider;

    #[ODM\Field(type: 'string')]
    private string $model;

    #[ODM\Field(type: 'string')]
    private string $version;

    #[ODM\Field(type: 'string')]
    private string $status; // PENDING, COMPLETED, FAILED

    #[ODM\Field(type: 'raw', nullable: true)]
    private ?array $result = null;

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $error = null;

    #[ODM\Field(type: 'int')]
    private int $attemptCount = 0;

    #[ODM\Field(type: 'int')]
    private int $retryCount = 0;

    #[ODM\Field(type: 'date_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ODM\Field(type: 'date_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ODM\Field(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(
        string $id,
        string $cacheKey,
        string $fileHash,
        int $pageNumber,
        string $provider,
        string $model,
        string $version
    ) {
        $this->id = $id;
        $this->cacheKey = $cacheKey;
        $this->fileHash = $fileHash;
        $this->pageNumber = $pageNumber;
        $this->provider = $provider;
        $this->model = $model;
        $this->version = $version;
        $this->status = 'PENDING';
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCacheKey(): string
    {
        return $this->cacheKey;
    }

    public function getFileHash(): string
    {
        return $this->fileHash;
    }

    public function getPageNumber(): int
    {
        return $this->pageNumber;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getResult(): ?array
    {
        return $this->result;
    }

    public function setResult(array $result): self
    {
        $this->result = $result;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): self
    {
        $this->error = $error;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function incrementAttemptCount(): self
    {
        $this->attemptCount++;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function incrementRetryCount(): self
    {
        $this->retryCount++;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markCompleted(array $result): void
    {
        $this->status = 'COMPLETED';
        $this->result = $result;
        $this->completedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $error): void
    {
        $this->status = 'FAILED';
        $this->error = $error;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isCompleted(): bool
    {
        return $this->status === 'COMPLETED';
    }

    public function isFailed(): bool
    {
        return $this->status === 'FAILED';
    }

    public function canRetry(): bool
    {
        return $this->status === 'FAILED' && $this->retryCount < 1;
    }
}