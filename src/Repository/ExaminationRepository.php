<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\ExamSubject;
use App\Entity\Examination;
use App\Entity\Institute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class ExaminationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Examination::class);
    }

    public function findById(Uuid $id, ?Uuid $instituteId = null): ?Examination
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.id = :id')
            ->setParameter('id', $id);

        if ($instituteId !== null) {
            $qb->andWhere('e.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByInstitute(Uuid $instituteId): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.institute = :institute')
            ->setParameter('institute', $instituteId)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPaginated(
        ?Uuid $instituteId,
        ?string $search,
        ?Uuid $academicYearId,
        ?Uuid $academicClassId,
        ?string $status,
        ?string $startDateFrom,
        ?string $startDateTo,
        ?string $endDateFrom,
        ?string $endDateTo,
        int $page,
        int $limit,
        string $sortBy,
        string $sortOrder
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.institute', 'i')
            ->addSelect('i')
            ->leftJoin('e.academicYear', 'ay')
            ->addSelect('ay')
            ->leftJoin('e.academicClass', 'ac')
            ->addSelect('ac');

        if ($instituteId !== null) {
            $qb->where('e.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        $this->applySearch($qb, $search);
        $this->applyAcademicYearFilter($qb, $academicYearId);
        $this->applyClassFilter($qb, $academicClassId);
        $this->applyStatusFilter($qb, $status);
        $this->applyStartDateRange($qb, $startDateFrom, $startDateTo);
        $this->applyEndDateRange($qb, $endDateFrom, $endDateTo);
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

    public function codeExistsForInstitute(string $code, Uuid $instituteId, ?Uuid $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.institute = :institute')
            ->andWhere('e.code = :code')
            ->setParameter('institute', $instituteId)
            ->setParameter('code', mb_strtoupper($code));

        if (null !== $excludeId) {
            $qb->andWhere('e.id != :excludeId')
               ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $search = trim(mb_strtolower($search));

        $qb->andWhere(
            $qb->expr()->orX(
                $qb->expr()->like('LOWER(e.name)', ':search'),
                $qb->expr()->like('LOWER(e.code)', ':search'),
                $qb->expr()->like('LOWER(e.description)', ':search')
            )
        )->setParameter('search', '%' . $search . '%');
    }

    private function applyAcademicYearFilter(QueryBuilder $qb, ?Uuid $academicYearId): void
    {
        if (null === $academicYearId) {
            return;
        }

        $qb->andWhere('e.academicYear = :academicYear')
           ->setParameter('academicYear', $academicYearId);
    }

    private function applyClassFilter(QueryBuilder $qb, ?Uuid $academicClassId): void
    {
        if (null === $academicClassId) {
            return;
        }

        $qb->andWhere('e.academicClass = :academicClass')
           ->setParameter('academicClass', $academicClassId);
    }

    private function applyStatusFilter(QueryBuilder $qb, ?string $status): void
    {
        if (null === $status || '' === $status) {
            return;
        }

        $qb->andWhere('e.status = :status')
           ->setParameter('status', $status);
    }

    private function applyStartDateRange(QueryBuilder $qb, ?string $startDateFrom, ?string $startDateTo): void
    {
        if ($startDateFrom !== null && $startDateFrom !== '') {
            $qb->andWhere('e.startDate >= :startDateFrom')
               ->setParameter('startDateFrom', $startDateFrom);
        }

        if ($startDateTo !== null && $startDateTo !== '') {
            $qb->andWhere('e.startDate <= :startDateTo')
               ->setParameter('startDateTo', $startDateTo);
        }
    }

    private function applyEndDateRange(QueryBuilder $qb, ?string $endDateFrom, ?string $endDateTo): void
    {
        if ($endDateFrom !== null && $endDateFrom !== '') {
            $qb->andWhere('e.endDate >= :endDateFrom')
               ->setParameter('endDateFrom', $endDateFrom);
        }

        if ($endDateTo !== null && $endDateTo !== '') {
            $qb->andWhere('e.endDate <= :endDateTo')
               ->setParameter('endDateTo', $endDateTo);
        }
    }

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['name', 'code', 'startDate', 'endDate', 'createdAt', 'updatedAt'];

        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'createdAt';
        }

        $sortOrder = mb_strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $qb->addOrderBy('e.' . $sortBy, $sortOrder);
    }

    private function getTotalCount(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(e.id)');
        $countQb->resetDQLPart('orderBy');

        return (int) $countQb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array<string, int>
     */
    public function findExamSubjectCountsByExaminationIds(array $examinationIds): array
    {
        if (empty($examinationIds)) {
            return [];
        }

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(es.examination) AS examinationId, COUNT(es.id) AS subjectCount')
            ->from(ExamSubject::class, 'es')
            ->where('es.examination IN (:examinationIds)')
            ->setParameter('examinationIds', $examinationIds)
            ->groupBy('es.examination');

        $results = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[(string) $row['examinationId']] = (int) $row['subjectCount'];
        }

        return $counts;
    }
}
