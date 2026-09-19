<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\TeacherAssignment;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\Institute;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class TeacherAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeacherAssignment::class);
    }

    public function findById(Uuid $id, ?Uuid $instituteId = null): ?TeacherAssignment
    {
        $qb = $this->createQueryBuilder('ta')
            ->where('ta.id = :id')
            ->setParameter('id', $id);

        if ($instituteId !== null) {
            $qb->innerJoin('ta.academicClass', 'c')
               ->andWhere('c.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByInstitute(Uuid $instituteId): array
    {
        return $this->createQueryBuilder('ta')
            ->innerJoin('ta.academicClass', 'c')
            ->where('c.institute = :institute')
            ->setParameter('institute', $instituteId)
            ->orderBy('ta.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPaginated(
        ?Uuid $instituteId,
        ?Uuid $teacherId,
        ?Uuid $academicYearId,
        ?Uuid $classId,
        ?Uuid $sectionId,
        ?Uuid $subjectId,
        ?string $search,
        ?string $status,
        int $page,
        int $limit,
        string $sortBy,
        string $sortOrder
    ): array {
        $qb = $this->createQueryBuilder('ta');

        if ($instituteId !== null) {
            $qb->innerJoin('ta.academicClass', 'c')
               ->where('c.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        $this->applyTeacherFilter($qb, $teacherId);
        $this->applyAcademicYearFilter($qb, $academicYearId);
        $this->applyClassFilter($qb, $classId);
        $this->applySectionFilter($qb, $sectionId);
        $this->applySubjectFilter($qb, $subjectId);
        $this->applySearch($qb, $search);
        $this->applyStatusFilter($qb, $status);
        $this->applySorting($qb, $sortBy, $sortOrder);

        $totalItems = $this->getTotalCount($qb);

        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)
           ->setMaxResults($limit);

        $items = $qb->getQuery()->getResult();

        $totalPages = $limit > 0 ? (int) ceil($totalItems / $limit) : 0;

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages,
            ],
        ];
    }

    public function findActiveDuplicate(
        User $teacher,
        AcademicYear $academicYear,
        AcademicClass $academicClass,
        ?Section $section,
        ?Subject $subject
    ): ?TeacherAssignment {
        $qb = $this->createQueryBuilder('ta')
            ->where('ta.teacher = :teacher')
            ->andWhere('ta.academicYear = :academicYear')
            ->andWhere('ta.academicClass = :academicClass')
            ->andWhere('ta.status = :status')
            ->setParameter('teacher', $teacher)
            ->setParameter('academicYear', $academicYear)
            ->setParameter('academicClass', $academicClass)
            ->setParameter('status', 'ACTIVE');

        if ($section !== null) {
            $qb->andWhere('ta.section = :section')
               ->setParameter('section', $section);
        } else {
            $qb->andWhere('ta.section IS NULL');
        }

        if ($subject !== null) {
            $qb->andWhere('ta.subject = :subject')
               ->setParameter('subject', $subject);
        } else {
            $qb->andWhere('ta.subject IS NULL');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    private function applyTeacherFilter(QueryBuilder $qb, ?Uuid $teacherId): void
    {
        if (null === $teacherId) {
            return;
        }

        $qb->andWhere('ta.teacher = :teacher')
           ->setParameter('teacher', $teacherId);
    }

    private function applyAcademicYearFilter(QueryBuilder $qb, ?Uuid $academicYearId): void
    {
        if (null === $academicYearId) {
            return;
        }

        $qb->andWhere('ta.academicYear = :academicYear')
           ->setParameter('academicYear', $academicYearId);
    }

    private function applyClassFilter(QueryBuilder $qb, ?Uuid $classId): void
    {
        if (null === $classId) {
            return;
        }

        $qb->andWhere('ta.academicClass = :classId')
           ->setParameter('classId', $classId);
    }

    private function applySectionFilter(QueryBuilder $qb, ?Uuid $sectionId): void
    {
        if (null === $sectionId) {
            return;
        }

        $qb->andWhere('ta.section = :sectionId')
           ->setParameter('sectionId', $sectionId);
    }

    private function applySubjectFilter(QueryBuilder $qb, ?Uuid $subjectId): void
    {
        if (null === $subjectId) {
            return;
        }

        $qb->andWhere('ta.subject = :subjectId')
           ->setParameter('subjectId', $subjectId);
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $search = trim(mb_strtolower($search));

        $qb->andWhere(
            $qb->expr()->orX(
                $qb->expr()->like('LOWER(ta.assignmentType)', ':search'),
                $qb->expr()->like('LOWER(ta.remarks)', ':search')
            )
        )->setParameter('search', '%' . $search . '%');
    }

    private function applyStatusFilter(QueryBuilder $qb, ?string $status): void
    {
        if (null === $status || '' === $status) {
            return;
        }

        $qb->andWhere('ta.status = :status')
           ->setParameter('status', $status);
    }

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['createdAt', 'updatedAt', 'assignmentType', 'status'];

        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'createdAt';
        }

        $sortOrder = mb_strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $qb->addOrderBy('ta.' . $sortBy, $sortOrder);
    }

    private function getTotalCount(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(ta.id)');
        $countQb->resetDQLPart('orderBy');

        return (int) $countQb->getQuery()->getSingleScalarResult();
    }

    public function archiveByAcademicYear(Uuid $academicYearId): void
    {
        $this->createQueryBuilder('ta')
            ->update()
            ->set('ta.status', ':status')
            ->where('ta.academicYear = :academicYear')
            ->setParameter('status', 'ARCHIVED')
            ->setParameter('academicYear', $academicYearId)
            ->getQuery()
            ->execute();
    }

    public function countDistinctTeachersByAcademicYear(Uuid $academicYearId): int
    {
        return (int) $this->createQueryBuilder('ta')
            ->select('COUNT(DISTINCT ta.teacher)')
            ->where('ta.academicYear = :academicYear')
            ->setParameter('academicYear', $academicYearId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findActiveByTeacherAndClassAndSection(
        Uuid $teacherId,
        Uuid $academicYearId,
        Uuid $classId,
        ?Uuid $sectionId = null
    ): ?TeacherAssignment {
        $qb = $this->createQueryBuilder('ta')
            ->where('ta.teacher = :teacher')
            ->andWhere('ta.academicYear = :academicYear')
            ->andWhere('ta.academicClass = :academicClass')
            ->andWhere('ta.status = :status')
            ->setParameter('teacher', $teacherId)
            ->setParameter('academicYear', $academicYearId)
            ->setParameter('academicClass', $classId)
            ->setParameter('status', 'ACTIVE');

        if ($sectionId !== null) {
            $qb->andWhere('ta.section = :section')
                ->setParameter('section', $sectionId);
        }

        return $qb->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
