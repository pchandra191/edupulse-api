<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\AttendancePolicy;
use App\Entity\Institute;
use App\Entity\AcademicYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class AttendancePolicyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AttendancePolicy::class);
    }

    public function findById(Uuid $id, ?Uuid $instituteId = null): ?AttendancePolicy
    {
        $qb = $this->createQueryBuilder('ap')
            ->where('ap.id = :id')
            ->setParameter('id', $id);

        if ($instituteId !== null) {
            $qb->andWhere('ap.institute = :institute')
                ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByInstitute(Uuid $instituteId): array
    {
        return $this->createQueryBuilder('ap')
            ->where('ap.institute = :institute')
            ->setParameter('institute', $instituteId)
            ->orderBy('ap.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveByInstituteAndYear(?Uuid $instituteId, ?Uuid $academicYearId = null): ?AttendancePolicy
    {
        $qb = $this->createQueryBuilder('ap')
            ->where('ap.isActive = :active')
            ->setParameter('active', true);

        if ($instituteId !== null) {
            $qb->andWhere('ap.institute = :institute')
                ->setParameter('institute', $instituteId);
        }

        if ($academicYearId !== null) {
            $qb->andWhere('ap.academicYear = :academicYear')
                ->setParameter('academicYear', $academicYearId);
        } else {
            $qb->andWhere('ap.academicYear IS NULL');
        }

        return $qb->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findPaginated(
        ?Uuid $instituteId,
        ?Uuid $academicYearId,
        ?string $status,
        int $page,
        int $limit,
        string $sortBy,
        string $sortOrder
    ): array {
        $qb = $this->createQueryBuilder('ap')
            ->leftJoin('ap.institute', 'i')
            ->addSelect('i')
            ->leftJoin('ap.academicYear', 'ay')
            ->addSelect('ay');

        if ($instituteId !== null) {
            $qb->where('ap.institute = :institute')
                ->setParameter('institute', $instituteId);
        }

        $this->applyAcademicYearFilter($qb, $academicYearId);
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

    private function applyAcademicYearFilter(QueryBuilder $qb, ?Uuid $academicYearId): void
    {
        if (null === $academicYearId) {
            return;
        }

        $qb->andWhere('ap.academicYear = :academicYear')
            ->setParameter('academicYear', $academicYearId);
    }

    private function applyStatusFilter(QueryBuilder $qb, ?string $status): void
    {
        if (null === $status || '' === $status) {
            return;
        }

        $qb->andWhere('ap.isActive = :status')
            ->setParameter('status', $status === 'true' || $status === '1' ? true : false);
    }

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['createdAt', 'minimumAttendancePercentage', 'isActive'];

        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'createdAt';
        }

        $sortOrder = mb_strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $qb->addOrderBy('ap.' . $sortBy, $sortOrder);
    }

    private function getTotalCount(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(ap.id)');
        $countQb->resetDQLPart('orderBy');

        return (int) $countQb->getQuery()->getSingleScalarResult();
    }
}
