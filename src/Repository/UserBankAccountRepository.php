<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserBankAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class UserBankAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserBankAccount::class);
    }

    public function findByUserId(Uuid $userId): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.userId = :userId')
            ->andWhere('b.deletedAt IS NULL')
            ->orderBy('b.isPrimary', 'DESC')
            ->addOrderBy('b.createdAt', 'DESC')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();
    }

    public function findPrimaryByUserId(Uuid $userId): ?UserBankAccount
    {
        return $this->createQueryBuilder('b')
            ->where('b.userId = :userId')
            ->andWhere('b.isPrimary = true')
            ->andWhere('b.deletedAt IS NULL')
            ->setParameter('userId', $userId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
