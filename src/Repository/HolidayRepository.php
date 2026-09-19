<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Holiday;
use App\Entity\Institute;
use App\Entity\AcademicYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class HolidayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Holiday::class);
    }

    public function findById(Uuid $id, ?Uuid $instituteId = null): ?Holiday
    {
        $qb = $this->createQueryBuilder('h')
            ->where('h.id = :id')
            ->setParameter('id', $id);

        if ($instituteId !== null) {
            $qb->andWhere('h.institute = :institute')
                ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByInstitute(Uuid $instituteId): array
    {
        return $this->createQueryBuilder('h')
            ->where('h.institute = :institute')
            ->andWhere('h.enabled = :enabled')
            ->setParameter('institute', $instituteId)
            ->setParameter('enabled', true)
            ->orderBy('h.holidayDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findPaginated(
        ?Uuid $instituteId,
        ?Uuid $academicYearId,
        ?\DateTimeImmutable $date,
        ?string $enabled,
        int $page,
        int $limit,
        string $sortBy,
        string $sortOrder
    ): array {
        $qb = $this->createQueryBuilder('h')
            ->leftJoin('h.institute', 'i')
            ->addSelect('i')
            ->leftJoin('h.academicYear', 'ay')
            ->addSelect('ay');

        if ($instituteId !== null) {
            $qb->where('h.institute = :institute')
                ->setParameter('institute', $instituteId);
        }

        $this->applyAcademicYearFilter($qb, $academicYearId);
        $this->applyDateFilter($qb, $date);
        $this->applyEnabledFilter($qb, $enabled);
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

    public function isHoliday(\DateTimeImmutable $date, ?Uuid $instituteId = null, ?Uuid $academicYearId = null): bool
    {
        $qb = $this->createQueryBuilder('h')
            ->select('COUNT(h.id)')
            ->where('h.holidayDate = :date')
            ->andWhere('h.enabled = :enabled')
            ->setParameter('date', $date)
            ->setParameter('enabled', true);

        if ($instituteId !== null) {
            $qb->andWhere('(h.institute = :institute OR h.institute IS NULL)')
                ->setParameter('institute', $instituteId);
        }

        if ($academicYearId !== null) {
            $qb->andWhere('h.academicYear = :academicYear OR h.academicYear IS NULL')
                ->setParameter('academicYear', $academicYearId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function findHolidaysInDateRange(
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        ?Uuid $instituteId = null,
        ?Uuid $academicYearId = null
    ): array {
        $qb = $this->createQueryBuilder('h')
            ->where('h.holidayDate >= :startDate')
            ->andWhere('h.holidayDate <= :endDate')
            ->andWhere('h.enabled = :enabled')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->setParameter('enabled', true)
            ->orderBy('h.holidayDate', 'ASC');

        if ($instituteId !== null) {
            $qb->andWhere('(h.institute = :institute OR h.institute IS NULL)')
                ->setParameter('institute', $instituteId);
        }

        if ($academicYearId !== null) {
            $qb->andWhere('h.academicYear = :academicYear OR h.academicYear IS NULL')
                ->setParameter('academicYear', $academicYearId);
        }

        return $qb->getQuery()->getResult();
    }

    public function dateExists(
        \DateTimeImmutable $date,
        ?Uuid $instituteId = null,
        ?Uuid $excludeId = null
    ): bool {
        $qb = $this->createQueryBuilder('h')
            ->select('COUNT(h.id)')
            ->where('h.holidayDate = :date')
            ->setParameter('date', $date);

        if ($instituteId !== null) {
            $qb->andWhere('(h.institute = :institute OR h.institute IS NULL)')
                ->setParameter('institute', $instituteId);
        }

        if (null !== $excludeId) {
            $qb->andWhere('h.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    private function applyAcademicYearFilter(QueryBuilder $qb, ?Uuid $academicYearId): void
    {
        if (null === $academicYearId) {
            return;
        }

        $qb->andWhere('h.academicYear = :academicYear')
            ->setParameter('academicYear', $academicYearId);
    }

    private function applyDateFilter(QueryBuilder $qb, ?\DateTimeImmutable $date): void
    {
        if (null === $date) {
            return;
        }

        $qb->andWhere('h.holidayDate = :date')
            ->setParameter('date', $date);
    }

    private function applyEnabledFilter(QueryBuilder $qb, ?string $enabled): void
    {
        if (null === $enabled || '' === $enabled) {
            return;
        }

        $qb->andWhere('h.enabled = :enabled')
            ->setParameter('enabled', $enabled === 'true' || $enabled === '1' ? true : false);
    }

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['holidayDate', 'name', 'createdAt', 'enabled'];

        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'holidayDate';
        }

        $sortOrder = mb_strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $qb->addOrderBy('h.' . $sortBy, $sortOrder);
    }

    private function getTotalCount(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(h.id)');
        $countQb->resetDQLPart('orderBy');

        return (int) $countQb->getQuery()->getSingleScalarResult();
    }
}
