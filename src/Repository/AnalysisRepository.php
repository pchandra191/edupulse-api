<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Analysis;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Analysis>
 */
class AnalysisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Analysis::class);
    }

    public function findByStudent(User $student): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.student = :student')
            ->setParameter('student', $student)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByAnalysisIdAndStudent(string $analysisId, User $student): ?Analysis
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.analysisId = :analysisId')
            ->andWhere('a.student = :student')
            ->setParameter('analysisId', $analysisId)
            ->setParameter('student', $student)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByAnalysisId(string $analysisId): ?Analysis
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.analysisId = :analysisId')
            ->setParameter('analysisId', $analysisId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array<Analysis>
     */
    public function findByStudentPaginated(User $student, int $page, int $limit): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.student = :student')
            ->setParameter('student', $student)
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByStudent(User $student): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.student = :student')
            ->setParameter('student', $student)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Returns analyses owned by the student that carry a meaningful subject,
     * i.e. those referencing a Subject entity or holding a non-empty subjectName.
     * Used to build a student's historical subject options. The query is strictly
     * scoped to the supplied student; no other student's data can leak through.
     *
     * @return list<Analysis>
     */
    public function findSubjectHistoryForStudent(User $student): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('s')
            ->leftJoin('a.subject', 's')
            ->andWhere('a.student = :student')
            ->andWhere('(a.subjectName IS NOT NULL AND a.subjectName <> :empty) OR s.id IS NOT NULL')
            ->setParameter('student', $student)
            ->setParameter('empty', '')
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}