<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\StudentEnrollment;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\EnrollmentSection;
use App\Entity\Institute;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class StudentEnrollmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StudentEnrollment::class);
    }

    public function findById(Uuid $id, ?Uuid $instituteId = null): ?StudentEnrollment
    {
        $qb = $this->createQueryBuilder('se')
            ->where('se.id = :id')
            ->setParameter('id', $id);

        if ($instituteId !== null) {
            $qb->innerJoin('se.academicClass', 'c')
               ->andWhere('c.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByInstitute(Uuid $instituteId): array
    {
        return $this->createQueryBuilder('se')
            ->innerJoin('se.academicClass', 'c')
            ->where('c.institute = :institute')
            ->setParameter('institute', $instituteId)
            ->orderBy('se.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPaginated(
        ?Uuid $instituteId,
        ?Uuid $studentId,
        ?Uuid $academicYearId,
        ?Uuid $classId,
        ?Uuid $sectionId,
        ?string $search,
        ?string $status,
        int $page,
        int $limit,
        string $sortBy,
        string $sortOrder
    ): array {
        $qb = $this->createQueryBuilder('se')
            ->leftJoin('se.student', 'u')
            ->addSelect('u')
            ->leftJoin('se.academicYear', 'ay')
            ->addSelect('ay')
            ->leftJoin('se.academicClass', 'c')
            ->addSelect('c')
            ->leftJoin('ay.institute', 'i')
            ->addSelect('i');

        if ($instituteId !== null) {
            $qb->where('i.id = :institute')
               ->setParameter('institute', $instituteId);
        }

        $this->applyStudentFilter($qb, $studentId);
        $this->applyAcademicYearFilter($qb, $academicYearId);
        $this->applyClassFilter($qb, $classId);
        $this->applySectionFilter($qb, $sectionId);
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

    public function findCurrentByStudent(Uuid $studentId): ?StudentEnrollment
    {
        return $this->createQueryBuilder('se')
            ->where('se.student = :student')
            ->andWhere('se.isCurrent = :isCurrent')
            ->setParameter('student', $studentId)
            ->setParameter('isCurrent', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveEnrollmentByStudent(Uuid $studentId): ?StudentEnrollment
    {
        return $this->createQueryBuilder('se')
            ->where('se.student = :student')
            ->andWhere('se.status = :status')
            ->setParameter('student', $studentId)
            ->setParameter('status', 'ACTIVE')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function rollNumberExistsForClass(string $rollNumber, Uuid $academicYearId, Uuid $classId, ?Uuid $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('se')
            ->select('COUNT(se.id)')
            ->where('se.academicYear = :academicYear')
            ->andWhere('se.academicClass = :class')
            ->andWhere('se.rollNumber = :rollNumber')
            ->setParameter('academicYear', $academicYearId)
            ->setParameter('class', $classId)
            ->setParameter('rollNumber', $rollNumber);

        if (null !== $excludeId) {
            $qb->andWhere('se.id != :excludeId')
               ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function admissionNumberExistsForInstitute(string $admissionNumber, Uuid $instituteId, Uuid $academicYearId, ?Uuid $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('se')
            ->select('COUNT(se.id)')
            ->where('se.instituteId = :institute')
            ->andWhere('se.academicYear = :academicYear')
            ->andWhere('se.admissionNumber = :admissionNumber')
            ->setParameter('institute', $instituteId)
            ->setParameter('academicYear', $academicYearId)
            ->setParameter('admissionNumber', mb_strtoupper($admissionNumber));

        if (null !== $excludeId) {
            $qb->andWhere('se.id != :excludeId')
               ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function hasActiveEnrollment(Uuid $studentId, Uuid $academicYearId, Uuid $academicClassId): bool
    {
        return (bool) $this->createQueryBuilder('se')
            ->select('COUNT(se.id)')
            ->where('se.student = :student')
            ->andWhere('se.academicYear = :academicYear')
            ->andWhere('se.academicClass = :class')
            ->setParameter('student', $studentId)
            ->setParameter('academicYear', $academicYearId)
            ->setParameter('class', $academicClassId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function applyStudentFilter(QueryBuilder $qb, ?Uuid $studentId): void
    {
        if (null === $studentId) {
            return;
        }

        $qb->andWhere('se.student = :student')
           ->setParameter('student', $studentId);
    }

    private function applyAcademicYearFilter(QueryBuilder $qb, ?Uuid $academicYearId): void
    {
        if (null === $academicYearId) {
            return;
        }

        $qb->andWhere('se.academicYear = :academicYear')
           ->setParameter('academicYear', $academicYearId);
    }

    private function applyClassFilter(QueryBuilder $qb, ?Uuid $classId): void
    {
        if (null === $classId) {
            return;
        }

        $qb->andWhere('se.academicClass = :class')
           ->setParameter('class', $classId);
    }

    private function applySectionFilter(QueryBuilder $qb, ?Uuid $sectionId): void
    {
        if (null === $sectionId) {
            return;
        }

        $qb->innerJoin('se.enrollmentSections', 'es')
           ->andWhere('es.section = :section')
           ->setParameter('section', $sectionId);
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $search = trim(mb_strtolower($search));

        $qb->innerJoin('se.student', 'u')
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('LOWER(se.rollNumber)', ':search'),
                    $qb->expr()->like('LOWER(se.admissionNumber)', ':search'),
                    $qb->expr()->like('LOWER(u.firstName)', ':search'),
                    $qb->expr()->like('LOWER(u.lastName)', ':search'),
                    $qb->expr()->like('LOWER(u.email)', ':search'),
                )
            )->setParameter('search', '%' . $search . '%');
    }

    private function applyStatusFilter(QueryBuilder $qb, ?string $status): void
    {
        if (null === $status || '' === $status) {
            return;
        }

        $qb->andWhere('se.status = :status')
           ->setParameter('status', $status);
    }

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['admissionDate', 'rollNumber', 'admissionNumber', 'createdAt'];

        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'createdAt';
        }

        $sortOrder = mb_strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $qb->addOrderBy('se.' . $sortBy, $sortOrder);
    }

    private function getTotalCount(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(se.id)');
        $countQb->resetDQLPart('orderBy');
        $countQb->resetDQLPart('groupBy');

        return (int) $countQb->getQuery()->getSingleScalarResult();
    }

    public function archiveByAcademicYear(Uuid $academicYearId): void
    {
        $this->createQueryBuilder('se')
            ->update()
            ->set('se.status', ':status')
            ->where('se.academicYear = :academicYear')
            ->setParameter('status', 'ARCHIVED')
            ->setParameter('academicYear', $academicYearId)
            ->getQuery()
            ->execute();
    }

    public function countByAcademicYear(Uuid $academicYearId): int
    {
        return (int) $this->createQueryBuilder('se')
            ->select('COUNT(DISTINCT se.student)')
            ->where('se.academicYear = :academicYear')
            ->setParameter('academicYear', $academicYearId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<int, string>
     */
    public function findIdsByAcademicYear(Uuid $academicYearId): array
    {
        return array_column(
            $this->createQueryBuilder('se')
                ->select('se.id')
                ->where('se.academicYear = :academicYear')
                ->setParameter('academicYear', $academicYearId)
                ->getQuery()
                ->getScalarResult(),
            'id'
        );
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function findEnrollmentSectionsByEnrollmentIds(array $enrollmentIds): array
    {
        if (empty($enrollmentIds)) {
            return [];
        }

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('es.id, IDENTITY(es.studentEnrollment) AS enrollmentId, es.status, es.joinedAt, es.leftAt, s.id AS sectionId, s.name AS sectionName, s.code AS sectionCode')
            ->from(EnrollmentSection::class, 'es')
            ->innerJoin('es.section', 's')
            ->where('es.studentEnrollment IN (:enrollmentIds)')
            ->setParameter('enrollmentIds', $enrollmentIds)
            ->orderBy('es.createdAt', 'DESC');

        $results = $qb->getQuery()->getArrayResult();

        $byEnrollment = [];
        foreach ($results as $row) {
            $enrollmentId = (string) $row['enrollmentId'];
            $byEnrollment[$enrollmentId][] = $row;
        }

        return $byEnrollment;
    }
}
