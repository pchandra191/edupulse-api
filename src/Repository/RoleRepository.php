<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Role;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class RoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Role::class);
    }

    public function findAllActive(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :status')
            ->setParameter('status', 'ACTIVE')
            ->orderBy('r.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByUuid(Uuid $id): ?Role
    {
        return $this->createQueryBuilder('r')
            ->where('r.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByCode(string $code): ?Role
    {
        return $this->createQueryBuilder('r')
            ->where('r.code = :code')
            ->setParameter('code', mb_strtoupper($code))
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function codeExists(string $code, ?Uuid $ignoreRole = null): bool
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.code = :code')
            ->setParameter('code', mb_strtoupper($code));

        if (null !== $ignoreRole) {
            $qb->andWhere('r.id != :ignoreRole')
               ->setParameter('ignoreRole', $ignoreRole);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
