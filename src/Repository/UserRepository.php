<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findActiveByIdentifier(string $identifier): ?User
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.email = :identifier')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('identifier', mb_strtolower(trim($identifier)));

        return $qb->getQuery()->getOneOrNullResult();
    }
}
