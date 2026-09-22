<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailVerificationToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EmailVerificationTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailVerificationToken::class);
    }

    public function findBySelector(string $selector): ?EmailVerificationToken
    {
        return $this->findOneBy(['selector' => $selector]);
    }

    public function findActiveByUser(User $user): ?EmailVerificationToken
    {
        $now = new \DateTimeImmutable();

        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.usedAt IS NULL')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', $now)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function deleteUsedBefore(\DateTimeImmutable $dateTime): void
    {
        $this->createQueryBuilder('t')
            ->delete()
            ->where('t.usedAt IS NOT NULL')
            ->andWhere('t.usedAt < :dateTime')
            ->setParameter('dateTime', $dateTime)
            ->getQuery()
            ->execute();
    }
}
