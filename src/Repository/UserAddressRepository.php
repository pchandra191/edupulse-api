<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserAddress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class UserAddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserAddress::class);
    }

    public function findByUserId(Uuid $userId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.userId = :userId')
            ->andWhere('a.deletedAt IS NULL')
            ->orderBy('a.isPrimary', 'DESC')
            ->addOrderBy('a.createdAt', 'DESC')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();
    }

    public function findByUserIdAndType(Uuid $userId, string $type): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.userId = :userId')
            ->andWhere('a.addressType = :type')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('userId', $userId)
            ->setParameter('type', $type)
            ->getQuery()
            ->getResult();
    }

    public function findPrimaryByUserId(Uuid $userId): ?UserAddress
    {
        return $this->createQueryBuilder('a')
            ->where('a.userId = :userId')
            ->andWhere('a.isPrimary = true')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('userId', $userId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
