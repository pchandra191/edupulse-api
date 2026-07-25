<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\RolePermission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class RolePermissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RolePermission::class);
    }

    public function findByRole(Role $role): array
    {
        return $this->createQueryBuilder('rp')
            ->where('rp.role = :role')
            ->setParameter('role', $role)
            ->getQuery()
            ->getResult();
    }

    public function findByRoleUuid(Uuid $roleId): array
    {
        return $this->createQueryBuilder('rp')
            ->where('rp.role = :roleId')
            ->setParameter('roleId', $roleId)
            ->getQuery()
            ->getResult();
    }

    public function findByPermission(Permission $permission): array
    {
        return $this->createQueryBuilder('rp')
            ->where('rp.permission = :permission')
            ->setParameter('permission', $permission)
            ->getQuery()
            ->getResult();
    }

    public function deleteByRole(Role $role): int
    {
        $qb = $this->createQueryBuilder('rp')
            ->delete()
            ->where('rp.role = :role')
            ->setParameter('role', $role);

        return $qb->getQuery()->execute();
    }

    public function deleteByRoleUuid(Uuid $roleId): int
    {
        $qb = $this->createQueryBuilder('rp')
            ->delete()
            ->where('rp.role = :roleId')
            ->setParameter('roleId', $roleId);

        return $qb->getQuery()->execute();
    }

    public function findPermissionsByRoleCodes(array $roleCodes): array
    {
        $qb = $this->createQueryBuilder('rp')
            ->select('DISTINCT p.code')
            ->innerJoin('rp.permission', 'p')
            ->innerJoin('rp.role', 'r')
            ->where('r.code IN (:codes)')
            ->setParameter('codes', $roleCodes);

        return array_column($qb->getQuery()->getArrayResult(), 'code');
    }
}
