<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

#[ODM\EmbeddedDocument]
class DocumentFile
{
    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $fileId = null;

    #[ODM\Field(type: 'string')]
    private string $status = 'NOT_UPLOADED';

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $originalFilename = null;

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $storedFilename = null;

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $mimeType = null;

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $fileSize = null;

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $page = null;

    #[ODM\Field(type: 'int', nullable: true)]
    private ?int $pageCount = null;

    public function getFileId(): ?string
    {
        return $this->fileId;
    }

    public function setFileId(?string $fileId): self
    {
        $this->fileId = $fileId;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getOriginalFilename(): ?string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(?string $originalFilename): self
    {
        $this->originalFilename = $originalFilename;
        return $this;
    }

    public function getStoredFilename(): ?string
    {
        return $this->storedFilename;
    }

    public function setStoredFilename(?string $storedFilename): self
    {
        $this->storedFilename = $storedFilename;
        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): self
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(?int $fileSize): self
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    public function getPage(): ?int
    {
        return $this->page;
    }

    public function setPage(?int $page): self
    {
        $this->page = $page;
        return $this;
    }

    public function getPageCount(): ?int
    {
        return $this->pageCount;
    }

    public function setPageCount(?int $pageCount): self
    {
        $this->pageCount = $pageCount;
        return $this;
    }
}