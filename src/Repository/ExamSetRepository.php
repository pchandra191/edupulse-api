<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExamSet;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExamSet>
 */
class ExamSetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExamSet::class);
    }

    public function findByIdAndStudent(string $examSetId, User $student): ?ExamSet
    {
        return $this->createQueryBuilder('es')
            ->andWhere('es.id = :id')
            ->andWhere('es.student = :student')
            ->setParameter('id', $examSetId)
            ->setParameter('student', $student)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByStudent(User $student): array
    {
        return $this->createQueryBuilder('es')
            ->andWhere('es.student = :student')
            ->setParameter('student', $student)
            ->orderBy('es.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByStudentPaginated(User $student, int $page, int $limit): array
    {
        return $this->createQueryBuilder('es')
            ->andWhere('es.student = :student')
            ->setParameter('student', $student)
            ->orderBy('es.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByStudent(User $student): int
    {
        return (int) $this->createQueryBuilder('es')
            ->select('COUNT(es.id)')
            ->andWhere('es.student = :student')
            ->setParameter('student', $student)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find ExamSet by student and metadata to prevent duplicates
     */
    public function findByStudentAndMetadata(
        User $student,
        ?string $academicYearId,
        ?string $academicYear,
        ?string $classId,
        ?string $academicClass,
        ?string $sectionId,
        ?string $section,
        ?string $examId,
        ?string $examName,
        ?string $examSubTypeId,
        ?string $examSubType
    ): ?ExamSet {
        $qb = $this->createQueryBuilder('es')
            ->andWhere('es.student = :student')
            ->setParameter('student', $student);

        if ($academicYearId !== null) {
            $qb->andWhere('es.academicYearId = :academicYearId')
                ->setParameter('academicYearId', $academicYearId);
        } else {
            $qb->andWhere('es.academicYearId IS NULL');
        }

        if ($academicYear !== null) {
            $qb->andWhere('es.academicYear = :academicYear')
                ->setParameter('academicYear', $academicYear);
        } else {
            $qb->andWhere('es.academicYear IS NULL');
        }

        if ($classId !== null) {
            $qb->andWhere('es.classId = :classId')
                ->setParameter('classId', $classId);
        } else {
            $qb->andWhere('es.classId IS NULL');
        }

        if ($academicClass !== null) {
            $qb->andWhere('es.academicClass = :academicClass')
                ->setParameter('academicClass', $academicClass);
        } else {
            $qb->andWhere('es.academicClass IS NULL');
        }

        if ($sectionId !== null) {
            $qb->andWhere('es.sectionId = :sectionId')
                ->setParameter('sectionId', $sectionId);
        } else {
            $qb->andWhere('es.sectionId IS NULL');
        }

        if ($section !== null) {
            $qb->andWhere('es.section = :section')
                ->setParameter('section', $section);
        } else {
            $qb->andWhere('es.section IS NULL');
        }

        if ($examId !== null) {
            $qb->andWhere('es.examId = :examId')
                ->setParameter('examId', $examId);
        } else {
            $qb->andWhere('es.examId IS NULL');
        }

        if ($examName !== null) {
            $qb->andWhere('es.examName = :examName')
                ->setParameter('examName', $examName);
        } else {
            $qb->andWhere('es.examName IS NULL');
        }

        if ($examSubTypeId !== null) {
            $qb->andWhere('es.examSubTypeId = :examSubTypeId')
                ->setParameter('examSubTypeId', $examSubTypeId);
        } else {
            $qb->andWhere('es.examSubTypeId IS NULL');
        }

        if ($examSubType !== null) {
            $qb->andWhere('es.examSubType = :examSubType')
                ->setParameter('examSubType', $examSubType);
        } else {
            $qb->andWhere('es.examSubType IS NULL');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Check if an analysis for a subject already exists in an ExamSet
     */
    public function hasAnalysisForSubjectInExamSet(string $examSetId, string $subjectId): bool
    {
        // This will be implemented after Analysis entity has exam_set_id and subject_id
        return false;
    }
}