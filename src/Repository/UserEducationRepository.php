<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserEducation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class UserEducationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserEducation::class);
    }

    public function findByUserId(Uuid $userId): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.userId = :userId')
            ->andWhere('e.deletedAt IS NULL')
            ->orderBy('e.startDate', 'DESC')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();
    }
}
