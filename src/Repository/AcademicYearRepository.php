<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\Institute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class AcademicYearRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AcademicYear::class);
    }

    public function findById(Uuid $id, ?Uuid $instituteId = null): ?AcademicYear
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->setParameter('id', $id);

        if ($instituteId !== null) {
            $qb->andWhere('a.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findNameById(Uuid $id, ?Uuid $instituteId = null): ?string
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a.name')
            ->where('a.id = :id')
            ->setParameter('id', $id);

        if ($instituteId !== null) {
            $qb->andWhere('a.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        $result = $qb->getQuery()->getSingleScalarResult();

        return $result !== null ? (string) $result : null;
    }

    public function findByInstitute(Uuid $instituteId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.institute = :institute')
            ->setParameter('institute', $instituteId)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPaginated(?Uuid $instituteId, ?string $search, ?string $status, int $page, int $limit, string $sortBy, string $sortOrder): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.institute', 'i')
            ->addSelect('i');

        if ($instituteId !== null) {
            $qb->where('a.institute = :institute')
               ->setParameter('institute', $instituteId);
        }

        $this->applySearch($qb, $search);
        $this->applyStatusFilter($qb, $status);
        $this->applyDefaultActiveFilter($qb, $status);
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

    public function findActiveByInstitute(Uuid $instituteId): ?AcademicYear
    {
        return $this->createQueryBuilder('a')
            ->where('a.institute = :institute')
            ->andWhere('a.status = :status')
            ->setParameter('institute', $instituteId)
            ->setParameter('status', 'ACTIVE')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function deactivateAllExcept(AcademicYear $academicYear): void
    {
        $qb = $this->createQueryBuilder('a')
            ->update()
            // ->set('a.status', ':status')
            ->set('a.isActive', ':isActive')
            ->where('a.institute = :institute')
            ->andWhere('a.id != :id')
            ->andWhere('a.status != :archivedStatus')
            // ->setParameter('status', 'INACTIVE')
            ->setParameter('isActive', false)
            ->setParameter('institute', $academicYear->getInstitute())
            ->setParameter('id', $academicYear->getId())
            ->setParameter('archivedStatus', 'ARCHIVED');

        $qb->getQuery()->execute();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $search = trim(mb_strtolower($search));

        $qb->andWhere(
            $qb->expr()->orX(
                $qb->expr()->like('LOWER(a.name)', ':search'),
                $qb->expr()->like('LOWER(a.code)', ':search')
            )
        )->setParameter('search', '%' . $search . '%');
    }

    private function applyStatusFilter(QueryBuilder $qb, ?string $status): void
    {
        if (null === $status || '' === $status) {
            return;
        }

        $qb->andWhere('a.status = :status')
           ->setParameter('status', $status);
    }

    private function applyDefaultActiveFilter(QueryBuilder $qb, ?string $status): void
    {
        if (null !== $status && '' !== $status) {
            return;
        }

        $qb->andWhere('a.status != :archivedStatus')
           ->setParameter('archivedStatus', 'ARCHIVED');
    }

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['name', 'code', 'startDate', 'endDate', 'createdAt'];

        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'createdAt';
        }

        $sortOrder = mb_strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $qb->addOrderBy('a.' . $sortBy, $sortOrder);
    }

    private function getTotalCount(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(a.id)');
        $countQb->resetDQLPart('orderBy');

        return (int) $countQb->getQuery()->getSingleScalarResult();
    }
}
