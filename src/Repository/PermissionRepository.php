<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Permission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class PermissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Permission::class);
    }

    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.module', 'ASC')
            ->addOrderBy('p.groupName', 'ASC')
            ->addOrderBy('p.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByUuid(Uuid $id): ?Permission
    {
        return $this->createQueryBuilder('p')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByCode(string $code): ?Permission
    {
        return $this->createQueryBuilder('p')
            ->where('p.code = :code')
            ->setParameter('code', mb_strtolower($code))
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByCodes(array $codes): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.code IN (:codes)')
            ->setParameter('codes', array_map('mb_strtolower', $codes))
            ->getQuery()
            ->getResult();
    }

    public function codeExists(string $code): bool
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.code = :code')
            ->setParameter('code', mb_strtolower($code));

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function findByGroup(string $groupName): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.groupName = :groupName')
            ->setParameter('groupName', $groupName)
            ->orderBy('p.code', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
