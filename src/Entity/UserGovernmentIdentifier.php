<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\GovernmentIdType;
use App\Enum\VerificationStatus;
use App\Repository\UserGovernmentIdentifierRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserGovernmentIdentifierRepository::class)]
#[ORM\Table(name: 'user_government_identifiers')]
#[ORM\Index(name: 'idx_gov_id_user', fields: ['userId'])]
#[ORM\Index(name: 'idx_gov_id_type', fields: ['type'])]
#[ORM\Index(name: 'idx_gov_id_verification', fields: ['verificationStatus'])]
#[ORM\UniqueConstraint(name: 'uniq_gov_id_user_type', columns: ['user_id', 'type'])]
class UserGovernmentIdentifier extends AbstractEntity
{
    #[ORM\Column(type: 'uuid')]
    private ?Uuid $userId = null;

    #[ORM\Column(length: 30)]
    private string $type = GovernmentIdType::Other->value;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $identifier;

    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $issuingCountry = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $issuingAuthority = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $issueDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiryDate = null;

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

    public function getType(): GovernmentIdType
    {
        return GovernmentIdType::from($this->type);
    }

    public function setType(GovernmentIdType $type): self
    {
        $this->type = $type->value;

        return $this;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): self
    {
        $this->identifier = trim($identifier);

        return $this;
    }

    public function getIssuingCountry(): ?string
    {
        return $this->issuingCountry;
    }

    public function setIssuingCountry(?string $issuingCountry): self
    {
        $this->issuingCountry = $issuingCountry !== null ? trim($issuingCountry) : null;

        return $this;
    }

    public function getIssuingAuthority(): ?string
    {
        return $this->issuingAuthority;
    }

    public function setIssuingAuthority(?string $issuingAuthority): self
    {
        $this->issuingAuthority = $issuingAuthority !== null ? trim($issuingAuthority) : null;

        return $this;
    }

    public function getIssueDate(): ?\DateTimeImmutable
    {
        return $this->issueDate;
    }

    public function setIssueDate(?\DateTimeImmutable $issueDate): self
    {
        $this->issueDate = $issueDate;

        return $this;
    }

    public function getExpiryDate(): ?\DateTimeImmutable
    {
        return $this->expiryDate;
    }

    public function setExpiryDate(?\DateTimeImmutable $expiryDate): self
    {
        $this->expiryDate = $expiryDate;

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
