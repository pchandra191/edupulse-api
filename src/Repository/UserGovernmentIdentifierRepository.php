<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserGovernmentIdentifier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class UserGovernmentIdentifierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserGovernmentIdentifier::class);
    }

    public function findByUserId(Uuid $userId): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.userId = :userId')
            ->andWhere('g.deletedAt IS NULL')
            ->orderBy('g.type', 'ASC')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();
    }

    public function findByUserIdAndType(Uuid $userId, string $type): ?UserGovernmentIdentifier
    {
        return $this->createQueryBuilder('g')
            ->where('g.userId = :userId')
            ->andWhere('g.type = :type')
            ->andWhere('g.deletedAt IS NULL')
            ->setParameter('userId', $userId)
            ->setParameter('type', $type)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
