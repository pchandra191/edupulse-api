<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\StudyTask;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class StudyTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StudyTask::class);
    }

    public function findByStudentId(Uuid $studentId): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.studentId = :studentId')
            ->andWhere('t.deletedAt IS NULL')
            ->orderBy('t.subject', 'ASC')
            ->addOrderBy('t.topic', 'ASC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->setParameter('studentId', $studentId)
            ->getQuery()
            ->getResult();
    }

    public function findByIdAndStudentId(string $id, Uuid $studentId): ?StudyTask
    {
        return $this->createQueryBuilder('t')
            ->where('t.id = :id')
            ->andWhere('t.studentId = :studentId')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->setParameter('studentId', $studentId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByRecommendationKeyAndStudentId(string $key, Uuid $studentId): ?StudyTask
    {
        return $this->createQueryBuilder('t')
            ->where('t.aiRecommendationKey = :key')
            ->andWhere('t.studentId = :studentId')
            ->setParameter('key', $key)
            ->setParameter('studentId', $studentId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function existsByKeyAndStudentId(string $key, Uuid $studentId): bool
    {
        return (bool) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.aiRecommendationKey = :key')
            ->andWhere('t.studentId = :studentId')
            ->setParameter('key', $key)
            ->setParameter('studentId', $studentId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByStudentId(Uuid $studentId): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.studentId = :studentId')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('studentId', $studentId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countCompletedByStudentId(Uuid $studentId): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.studentId = :studentId')
            ->andWhere('t.isCompleted = true')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('studentId', $studentId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
