<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class UserDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserDocument::class);
    }

    public function findByUserId(Uuid $userId): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.userId = :userId')
            ->andWhere('d.deletedAt IS NULL')
            ->orderBy('d.createdAt', 'DESC')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();
    }

    public function findByUserIdAndType(Uuid $userId, string $type): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.userId = :userId')
            ->andWhere('d.documentType = :type')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('userId', $userId)
            ->setParameter('type', $type)
            ->getQuery()
            ->getResult();
    }
}
