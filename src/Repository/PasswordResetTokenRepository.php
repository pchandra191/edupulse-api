<?php

namespace App\Repository;

use App\Entity\PasswordResetToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PasswordResetTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetToken::class);
    }

    public function findValidByTokenHash(string $tokenHash, \DateTimeImmutable $now): ?PasswordResetToken
    {
        return $this->createQueryBuilder('t')
            ->where('t.tokenHash = :tokenHash')
            ->andWhere('t.expiresAt > :now')
            ->andWhere('t.usedAt IS NULL')
            ->setParameter('tokenHash', $tokenHash)
            ->setParameter('now', $now)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestByEmail(string $email): ?PasswordResetToken
    {
        return $this->createQueryBuilder('t')
            ->where('t.email = :email')
            ->setParameter('email', $email)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByToken(string $token): ?PasswordResetToken
    {
        return $this->createQueryBuilder('t')
            ->where('t.token = :token')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
