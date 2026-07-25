<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\RolePermissionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RolePermissionRepository::class)]
#[ORM\Table(name: 'role_permissions')]
#[ORM\UniqueConstraint(name: 'uniq_role_permission', columns: ['role_id', 'permission_id'])]
class RolePermission extends AbstractEntity
{
    #[ORM\ManyToOne(inversedBy: 'rolePermissions', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Role $role = null;

    #[ORM\ManyToOne(inversedBy: 'rolePermissions', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Permission $permission = null;

    public function getRole(): ?Role
    {
        return $this->role;
    }

    public function setRole(?Role $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getPermission(): ?Permission
    {
        return $this->permission;
    }

    public function setPermission(?Permission $permission): self
    {
        $this->permission = $permission;

        return $this;
    }
}
