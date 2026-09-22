<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\InstituteStudentRegistrationLinkRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstituteStudentRegistrationLinkRepository::class)]
#[ORM\Table(name: 'institute_student_registration_links')]
#[ORM\UniqueConstraint(name: 'uniq_institute_student_registration_link_token', columns: ['token'])]
#[ORM\Index(name: 'idx_institute_student_registration_link_institute', fields: ['institute'])]
#[ORM\Index(name: 'idx_institute_student_registration_link_active', fields: ['isActive'])]
class InstituteStudentRegistrationLink extends AbstractEntity
{
    #[ORM\Column(length: 64)]
    private string $token;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\ManyToOne(targetEntity: Institute::class, inversedBy: 'studentRegistrationLinks')]
    #[ORM\JoinColumn(name: 'institute_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institute $institute;

    public function __construct()
    {
        parent::__construct();
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): self
    {
        $this->token = $token;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        if (!$isActive) {
            $this->revokedAt ??= new \DateTimeImmutable();
        } else {
            $this->revokedAt = null;
        }

        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeImmutable $revokedAt): self
    {
        $this->revokedAt = $revokedAt;

        if ($revokedAt !== null) {
            $this->isActive = false;
        }

        return $this;
    }

    public function revoke(): void
    {
        $this->isActive = false;
        $this->revokedAt ??= new \DateTimeImmutable();
    }

    public function getInstitute(): Institute
    {
        return $this->institute;
    }

    public function setInstitute(?Institute $institute): self
    {
        $this->institute = $institute;

        return $this;
    }
}
