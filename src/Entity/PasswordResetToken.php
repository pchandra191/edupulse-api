<?php

namespace App\Entity;

use App\Repository\PasswordResetTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PasswordResetTokenRepository::class)]
#[ORM\Table(name: 'password_reset_tokens')]
#[ORM\HasLifecycleCallbacks]
class PasswordResetToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private ?string $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private ?string $tokenHash = null;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $token = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getTokenHash(): ?string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): self
    {
        $this->tokenHash = $tokenHash;

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(string $token): self
    {
        $this->token = $token;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function setUsedAt(?\DateTimeImmutable $usedAt): self
    {
        $this->usedAt = $usedAt;

        return $this;
    }

    public function isValid(\DateTimeImmutable $now): bool
    {
        return null === $this->usedAt && $this->expiresAt > $now;
    }
}
