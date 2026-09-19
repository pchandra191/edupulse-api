<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\LeaveBalance;
use App\Entity\LeaveType;
use App\Entity\User;
use App\Entity\AcademicYear;
use App\Entity\Institute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class LeaveBalanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaveBalance::class);
    }

    public function findById(Uuid $id, ?Uuid $instituteId = null): ?LeaveBalance
    {
        $qb = $this->createQueryBuilder('lb')
            ->where('lb.id = :id')
            ->setParameter('id', $id);

        if ($instituteId !== null) {
            $qb->andWhere('lb.institute = :institute')
                ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByStudentAndYear(
        Uuid $studentId,
        Uuid $academicYearId,
        ?Uuid $instituteId = null
    ): array {
        $qb = $this->createQueryBuilder('lb')
            ->innerJoin('lb.leaveType', 'lt')
            ->addSelect('lt')
            ->innerJoin('lb.academicYear', 'ay')
            ->addSelect('ay')
            ->where('lb.student = :student')
            ->andWhere('lb.academicYear = :academicYear')
            ->setParameter('student', $studentId)
            ->setParameter('academicYear', $academicYearId);

        if ($instituteId !== null) {
            $qb->andWhere('(lb.institute = :institute OR lt.institute IS NULL)')
                ->setParameter('institute', $instituteId);
        }

        return $qb->orderBy('lt.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByStudentAndLeaveType(
        Uuid $studentId,
        Uuid $leaveTypeId,
        Uuid $academicYearId,
        ?Uuid $instituteId = null
    ): ?LeaveBalance {
        $qb = $this->createQueryBuilder('lb')
            ->where('lb.student = :student')
            ->andWhere('lb.leaveType = :leaveType')
            ->andWhere('lb.academicYear = :academicYear')
            ->setParameter('student', $studentId)
            ->setParameter('leaveType', $leaveTypeId)
            ->setParameter('academicYear', $academicYearId);

        if ($instituteId !== null) {
            $qb->andWhere('lb.institute = :institute')
                ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findPaginated(
        ?Uuid $instituteId,
        ?Uuid $studentId,
        ?Uuid $academicYearId,
        ?Uuid $leaveTypeId,
        int $page,
        int $limit,
        string $sortBy,
        string $sortOrder
    ): array {
        $qb = $this->createQueryBuilder('lb')
            ->innerJoin('lb.leaveType', 'lt')
            ->addSelect('lt')
            ->innerJoin('lb.academicYear', 'ay')
            ->addSelect('ay')
            ->innerJoin('lb.student', 's')
            ->addSelect('s');

        if ($instituteId !== null) {
            $qb->innerJoin('ay.institute', 'i')
                ->addSelect('i')
                ->where('i.id = :institute')
                ->setParameter('institute', $instituteId);
        }

        $this->applyStudentFilter($qb, $studentId);
        $this->applyAcademicYearFilter($qb, $academicYearId);
        $this->applyLeaveTypeFilter($qb, $leaveTypeId);
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

    public function sumPendingByStudentAndYear(Uuid $studentId, Uuid $academicYearId): int
    {
        $qb = $this->createQueryBuilder('lb')
            ->select('SUM(lb.pending)')
            ->where('lb.student = :student')
            ->andWhere('lb.academicYear = :academicYear')
            ->setParameter('student', $studentId)
            ->setParameter('academicYear', $academicYearId);

        return (int) ($qb->getQuery()->getSingleScalarResult() ?? 0);
    }

    public function sumPendingByStudentAndLeaveType(Uuid $studentId, Uuid $leaveTypeId, Uuid $academicYearId): int
    {
        return (int) $this->createQueryBuilder('lb')
            ->select('SUM(lb.pending)')
            ->where('lb.student = :student')
            ->andWhere('lb.leaveType = :leaveType')
            ->andWhere('lb.academicYear = :academicYear')
            ->setParameter('student', $studentId)
            ->setParameter('leaveType', $leaveTypeId)
            ->setParameter('academicYear', $academicYearId)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    private function applyStudentFilter(QueryBuilder $qb, ?Uuid $studentId): void
    {
        if (null === $studentId) {
            return;
        }

        $qb->andWhere('lb.student = :student')
            ->setParameter('student', $studentId);
    }

    private function applyAcademicYearFilter(QueryBuilder $qb, ?Uuid $academicYearId): void
    {
        if (null === $academicYearId) {
            return;
        }

        $qb->andWhere('lb.academicYear = :academicYear')
            ->setParameter('academicYear', $academicYearId);
    }

    private function applyLeaveTypeFilter(QueryBuilder $qb, ?Uuid $leaveTypeId): void
    {
        if (null === $leaveTypeId) {
            return;
        }

        $qb->andWhere('lb.leaveType = :leaveType')
            ->setParameter('leaveType', $leaveTypeId);
    }

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['createdAt', 'allocated', 'used', 'pending'];

        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'createdAt';
        }

        $sortOrder = mb_strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $qb->addOrderBy('lb.' . $sortBy, $sortOrder);
    }

    private function getTotalCount(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(lb.id)');
        $countQb->resetDQLPart('orderBy');

        return (int) $countQb->getQuery()->getSingleScalarResult();
    }
}
