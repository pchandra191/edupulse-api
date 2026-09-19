<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\LeaveTypeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LeaveTypeRepository::class)]
#[ORM\Table(name: 'leave_types')]
#[ORM\UniqueConstraint(name: 'uniq_leave_type_institute_code', columns: ['institute_id', 'code'])]
#[ORM\Index(name: 'idx_leave_type_enabled', fields: ['enabled'])]
class LeaveType extends AbstractEntity
{
    #[ORM\ManyToOne(targetEntity: Institute::class)]
    #[ORM\JoinColumn(name: 'institute_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Institute $institute = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 30)]
    private string $code;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = true;

    #[ORM\Column(type: Types::BOOLEAN, name: 'requires_approval')]
    private bool $requiresApproval = true;

    #[ORM\Column(type: Types::INTEGER, name: 'maximum_days', nullable: true)]
    private ?int $maximumDays = null;

    #[ORM\Column(type: Types::BOOLEAN, name: 'carry_forward')]
    private bool $carryForward = false;

    public function __construct()
    {
        parent::__construct();
    }

    public function getInstitute(): ?Institute
    {
        return $this->institute;
    }

    public function setInstitute(?Institute $institute): self
    {
        $this->institute = $institute;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = mb_strtoupper(trim($code));

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description !== null ? trim($description) : null;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function isRequiresApproval(): bool
    {
        return $this->requiresApproval;
    }

    public function setRequiresApproval(bool $requiresApproval): self
    {
        $this->requiresApproval = $requiresApproval;

        return $this;
    }

    public function getMaximumDays(): ?int
    {
        return $this->maximumDays;
    }

    public function setMaximumDays(?int $maximumDays): self
    {
        $this->maximumDays = $maximumDays;

        return $this;
    }

    public function isCarryForward(): bool
    {
        return $this->carryForward;
    }

    public function setCarryForward(bool $carryForward): self
    {
        $this->carryForward = $carryForward;

        return $this;
    }
}
