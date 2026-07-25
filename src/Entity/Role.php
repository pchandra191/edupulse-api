<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\RoleStatus;
use App\Repository\RoleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RoleRepository::class)]
#[ORM\Table(name: 'roles')]
#[ORM\Index(name: 'idx_role_code', fields: ['code'])]
#[ORM\Index(name: 'idx_role_status', fields: ['status'])]
class Role extends AbstractEntity
{
    #[ORM\Column(length: 50, unique: true)]
    private string $code;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = RoleStatus::Active->value;

    #[ORM\OneToMany(mappedBy: 'role', targetEntity: RolePermission::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $rolePermissions;

    public function __construct()
    {
        parent::__construct();
        $this->rolePermissions = new ArrayCollection();
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = mb_strtoupper($code);

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

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

    public function getStatus(): RoleStatus
    {
        return RoleStatus::from($this->status);
    }

    public function setStatus(RoleStatus $status): self
    {
        $this->status = $status->value;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === RoleStatus::Active->value;
    }

    public function getRolePermissions(): Collection
    {
        return $this->rolePermissions;
    }

    public function addRolePermission(RolePermission $rolePermission): self
    {
        if (!$this->rolePermissions->contains($rolePermission)) {
            $this->rolePermissions[] = $rolePermission;
            $rolePermission->setRole($this);
        }

        return $this;
    }

    public function removeRolePermission(RolePermission $rolePermission): self
    {
        if ($this->rolePermissions->removeElement($rolePermission)) {
            if ($rolePermission->getRole() === $this) {
                $rolePermission->setRole(null);
            }
        }

        return $this;
    }

    public function getPermissions(): array
    {
        return $this->rolePermissions->map(fn(RolePermission $rp) => $rp->getPermission())->toArray();
    }
}
