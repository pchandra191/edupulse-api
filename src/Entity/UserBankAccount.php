<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\BankAccountType;
use App\Enum\VerificationStatus;
use App\Repository\UserBankAccountRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserBankAccountRepository::class)]
#[ORM\Table(name: 'user_bank_accounts')]
#[ORM\Index(name: 'idx_bank_account_user', fields: ['userId'])]
#[ORM\Index(name: 'idx_bank_account_primary', fields: ['isPrimary'])]
#[ORM\Index(name: 'idx_bank_account_verification', fields: ['verificationStatus'])]
class UserBankAccount extends AbstractEntity
{
    #[ORM\Column(type: 'uuid')]
    private ?Uuid $userId = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $accountHolderName;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $accountNumber;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $bankName;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $branchName = null;

    #[Assert\Length(max: 20)]
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $ifsc = null;

    #[ORM\Column(length: 20)]
    private string $accountType = BankAccountType::Savings->value;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::BOOLEAN)]
    private bool $isPrimary = false;

    #[ORM\Column(length: 20)]
    private string $verificationStatus = VerificationStatus::Unverified->value;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE, nullable: true)]
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

    public function getAccountHolderName(): string
    {
        return $this->accountHolderName;
    }

    public function setAccountHolderName(string $accountHolderName): self
    {
        $this->accountHolderName = trim($accountHolderName);

        return $this;
    }

    public function getAccountNumber(): string
    {
        return $this->accountNumber;
    }

    public function setAccountNumber(string $accountNumber): self
    {
        $this->accountNumber = trim($accountNumber);

        return $this;
    }

    public function getBankName(): string
    {
        return $this->bankName;
    }

    public function setBankName(string $bankName): self
    {
        $this->bankName = trim($bankName);

        return $this;
    }

    public function getBranchName(): ?string
    {
        return $this->branchName;
    }

    public function setBranchName(?string $branchName): self
    {
        $this->branchName = $branchName !== null ? trim($branchName) : null;

        return $this;
    }

    public function getIfsc(): ?string
    {
        return $this->ifsc;
    }

    public function setIfsc(?string $ifsc): self
    {
        $this->ifsc = $ifsc !== null ? strtoupper(trim($ifsc)) : null;

        return $this;
    }

    public function getAccountType(): BankAccountType
    {
        return BankAccountType::from($this->accountType);
    }

    public function setAccountType(BankAccountType $accountType): self
    {
        $this->accountType = $accountType->value;

        return $this;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function setIsPrimary(bool $isPrimary): self
    {
        $this->isPrimary = $isPrimary;

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
