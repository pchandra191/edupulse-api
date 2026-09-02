<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserDocumentType;
use App\Enum\VerificationStatus;
use App\Repository\UserDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserDocumentRepository::class)]
#[ORM\Table(name: 'user_documents')]
#[ORM\Index(name: 'idx_user_document_user', fields: ['userId'])]
#[ORM\Index(name: 'idx_user_document_type', fields: ['documentType'])]
#[ORM\Index(name: 'idx_user_document_verification', fields: ['verificationStatus'])]
class UserDocument extends AbstractEntity
{
    #[ORM\Column(type: 'uuid')]
    private ?Uuid $userId = null;

    #[ORM\Column(length: 30)]
    private string $documentType = UserDocumentType::Other->value;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $fileName;

    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column(type: Types::BIGINT)]
    private int $fileSize;

    #[Assert\Length(max: 500)]
    #[ORM\Column(length: 500)]
    private string $storageReference;

    #[ORM\Column(length: 20)]
    private string $verificationStatus = VerificationStatus::Unverified->value;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    public function __construct()
    {
        parent::__construct();
    }

    public function getUserId(): ?Uuid
    {
        return $this->userId;
    }

    public function setUserId(?Uuid $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function getDocumentType(): UserDocumentType
    {
        return UserDocumentType::from($this->documentType);
    }

    public function setDocumentType(UserDocumentType $documentType): self
    {
        $this->documentType = $documentType->value;

        return $this;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function setFileName(string $fileName): self
    {
        $this->fileName = trim($fileName);

        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): self
    {
        $this->mimeType = strtolower(trim($mimeType));

        return $this;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): self
    {
        $this->fileSize = $fileSize;

        return $this;
    }

    public function getStorageReference(): string
    {
        return $this->storageReference;
    }

    public function setStorageReference(string $storageReference): self
    {
        $this->storageReference = trim($storageReference);

        return $this;
    }

    public function getVerificationStatus(): VerificationStatus
    {
        return VerificationStatus::from($this->verificationStatus);
    }

    public function setVerificationStatus(VerificationStatus $verificationStatus): self
    {
        $this->verificationStatus = $verificationStatus->value;

        return $this;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTimeImmutable $verifiedAt): self
    {
        $this->verifiedAt = $verifiedAt;

        return $this;
    }
}
