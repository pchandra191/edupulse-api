<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
use Symfony\Component\Uid\Uuid;

#[ODM\Document(collection: 'ai_audit_logs')]
#[ODM\Index(keys: ['analysisId' => 'asc'])]
#[ODM\Index(keys: ['studentId' => 'asc'])]
#[ODM\Index(keys: ['timestamp' => 'desc'])]
#[ODM\Index(keys: ['provider' => 'asc'])]
class AiAuditLog
{
    #[ODM\Id(strategy: 'NONE', type: 'string')]
    private string $id;

    #[ODM\Field(type: 'string')]
    private string $analysisId;

    #[ODM\Field(type: 'string')]
    private string $studentId;

    #[ODM\Field(type: 'string')]
    private string $provider;

    #[ODM\Field(type: 'string')]
    private string $model;

    #[ODM\Field(type: 'raw', nullable: true)]
    private ?array $request = null;

    #[ODM\Field(type: 'raw', nullable: true)]
    private ?array $response = null;

    #[ODM\Field(type: 'float')]
    private float $durationMs = 0.0;

    #[ODM\Field(type: 'bool')]
    private bool $success = false;

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $error = null;

    #[ODM\Field(type: 'raw', nullable: true)]
    private ?array $piiStripped = null;

    #[ODM\Field(type: 'date_immutable')]
    private \DateTimeImmutable $timestamp;

    public function __construct()
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->timestamp = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAnalysisId(): string
    {
        return $this->analysisId;
    }

    public function setAnalysisId(string $analysisId): self
    {
        $this->analysisId = $analysisId;
        return $this;
    }

    public function getStudentId(): string
    {
        return $this->studentId;
    }

    public function setStudentId(string $studentId): self
    {
        $this->studentId = $studentId;
        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $this->provider = $provider;
        return $this;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }

    public function getRequest(): ?array
    {
        return $this->request;
    }

    public function setRequest(?array $request): self
    {
        $this->request = $request;
        return $this;
    }

    public function getResponse(): ?array
    {
        return $this->response;
    }

    public function setResponse(?array $response): self
    {
        $this->response = $response;
        return $this;
    }

    public function getDurationMs(): float
    {
        return $this->durationMs;
    }

    public function setDurationMs(float $durationMs): self
    {
        $this->durationMs = $durationMs;
        return $this;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function setSuccess(bool $success): self
    {
        $this->success = $success;
        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): self
    {
        $this->error = $error;
        return $this;
    }

    public function getPiiStripped(): ?array
    {
        return $this->piiStripped;
    }

    public function setPiiStripped(?array $piiStripped): self
    {
        $this->piiStripped = $piiStripped;
        return $this;
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function setTimestamp(\DateTimeImmutable $timestamp): self
    {
        $this->timestamp = $timestamp;
        return $this;
    }
}