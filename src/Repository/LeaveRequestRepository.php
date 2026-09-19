<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\LeaveRequest;
use App\Entity\LeaveType;
use App\Entity\User;
use App\Entity\AcademicYear;
use App\Entity\AcademicClass;
use App\Entity\Section;
use App\Entity\Institute;
use App\Enum\LeaveRequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class LeaveRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaveRequest::class);
    }

    public function findById(Uuid $id, ?Uuid $instituteId = null): ?LeaveRequest
    {
        $qb = $this->createQueryBuilder('lr')
            ->where('lr.id = :id')
            ->setParameter('id', $id);

        if ($instituteId !== null) {
            $qb->andWhere('lr.institute = :institute')
                ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findPaginated(
        ?Uuid $instituteId,
        ?Uuid $studentId,
        ?Uuid $leaveTypeId,
        ?string $status,
        ?\DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        int $page,
        int $limit,
        string $sortBy,
        string $sortOrder
    ): array {
        $qb = $this->createQueryBuilder('lr')
            ->innerJoin('lr.student', 's')
            ->addSelect('s')
            ->innerJoin('lr.leaveType', 'lt')
            ->addSelect('lt')
            ->innerJoin('lr.academicYear', 'ay')
            ->addSelect('ay');

        if ($instituteId !== null) {
            $qb->innerJoin('ay.institute', 'i')
                ->addSelect('i')
                ->where('i.id = :institute')
                ->setParameter('institute', $instituteId);
        }

        $this->applyStudentFilter($qb, $studentId);
        $this->applyLeaveTypeFilter($qb, $leaveTypeId);
        $this->applyStatusFilter($qb, $status);
        $this->applyDateFilter($qb, $startDate, $endDate);
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

    public function findOverlappingRequests(
        User $student,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        ?Uuid $excludeId = null
    ): array {
        $qb = $this->createQueryBuilder('lr')
            ->where('lr.student = :student')
            ->andWhere('lr.status IN (:statuses)')
            ->andWhere('lr.startDate <= :endDate')
            ->andWhere('lr.endDate >= :startDate')
            ->setParameter('student', $student)
            ->setParameter('statuses', [LeaveRequestStatus::Pending->value, LeaveRequestStatus::Approved->value])
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate);

        if (null !== $excludeId) {
            $qb->andWhere('lr.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        $qb->orderBy('lr.createdAt', 'DESC');

        return $qb->getQuery()->getResult();
    }

    public function findPendingForStudent(Uuid $studentId, ?Uuid $instituteId = null): array
    {
        $qb = $this->createQueryBuilder('lr')
            ->where('lr.student = :student')
            ->andWhere('lr.status = :status')
            ->setParameter('student', $studentId)
            ->setParameter('status', LeaveRequestStatus::Pending->value);

        if ($instituteId !== null) {
            $qb->andWhere('lr.institute = :institute')
                ->setParameter('institute', $instituteId);
        }

        return $qb->orderBy('lr.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countPendingForStudent(Uuid $studentId, ?Uuid $instituteId = null): int
    {
        $qb = $this->createQueryBuilder('lr')
            ->select('COUNT(lr.id)')
            ->where('lr.student = :student')
            ->andWhere('lr.status = :status')
            ->setParameter('student', $studentId)
            ->setParameter('status', LeaveRequestStatus::Pending->value);

        if ($instituteId !== null) {
            $qb->andWhere('lr.institute = :institute')
                ->setParameter('institute', $instituteId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findPendingForTeacher(Uuid $teacherId, ?Uuid $instituteId = null): array
    {
        $qb = $this->createQueryBuilder('lr')
            ->innerJoin('lr.student', 's')
            ->innerJoin('lr.academicYear', 'ay')
            ->innerJoin('lr.academicClass', 'c')
            ->where('lr.status = :status')
            ->andWhere('lr.academicClass IN (:classes)')
            ->setParameter('status', LeaveRequestStatus::Pending->value)
            ->setParameter('classes', $this->getTeacherAssignedClasses($teacherId));

        if ($instituteId !== null) {
            $qb->innerJoin('ay.institute', 'i')
                ->andWhere('i.id = :institute')
                ->setParameter('institute', $instituteId);
        }

        return $qb->orderBy('lr.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countPendingForTeacher(Uuid $teacherId, ?Uuid $instituteId = null): int
    {
        $qb = $this->createQueryBuilder('lr')
            ->select('COUNT(lr.id)')
            ->innerJoin('lr.academicYear', 'ay')
            ->innerJoin('lr.academicClass', 'c')
            ->where('lr.status = :status')
            ->andWhere('lr.academicClass IN (:classes)')
            ->setParameter('status', LeaveRequestStatus::Pending->value)
            ->setParameter('classes', $this->getTeacherAssignedClasses($teacherId));

        if ($instituteId !== null) {
            $qb->innerJoin('ay.institute', 'i')
                ->andWhere('i.id = :institute')
                ->setParameter('institute', $instituteId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findApprovedByStudentAndDateRange(
        Uuid $studentId,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        ?Uuid $instituteId = null
    ): array {
        $qb = $this->createQueryBuilder('lr')
            ->where('lr.student = :student')
            ->andWhere('lr.status = :status')
            ->andWhere('lr.startDate <= :endDate')
            ->andWhere('lr.endDate >= :startDate')
            ->setParameter('student', $studentId)
            ->setParameter('status', LeaveRequestStatus::Approved->value)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate);

        if ($instituteId !== null) {
            $qb->andWhere('lr.institute = :institute')
                ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getResult();
    }

    public function calculateUsedAndPendingForBalance(
        Uuid $studentId,
        Uuid $leaveTypeId,
        Uuid $academicYearId,
        ?Uuid $instituteId = null
    ): array {
        $qb = $this->createQueryBuilder('lr')
            ->where('lr.student = :student')
            ->andWhere('lr.leaveType = :leaveType')
            ->andWhere('lr.academicYear = :academicYear')
            ->andWhere('lr.status IN (:statuses)')
            ->setParameter('student', $studentId)
            ->setParameter('leaveType', $leaveTypeId)
            ->setParameter('academicYear', $academicYearId)
            ->setParameter('statuses', [
                LeaveRequestStatus::Approved->value,
                LeaveRequestStatus::Pending->value,
            ]);

        if ($instituteId !== null) {
            $qb->andWhere('lr.institute = :institute')
                ->setParameter('institute', $instituteId);
        }

        $requests = $qb->getQuery()->getResult();

        $used = 0;
        $pending = 0;
        foreach ($requests as $request) {
            $days = $request->getTotalDays();
            if ($request->getStatus() === LeaveRequestStatus::Approved) {
                $used += $days;
            } elseif ($request->getStatus() === LeaveRequestStatus::Pending) {
                $pending += $days;
            }
        }

        return ['used' => $used, 'pending' => $pending];
    }

    private function getTeacherAssignedClasses(Uuid $teacherId): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('ta.academicClass')
            ->from('App\Entity\TeacherAssignment', 'ta')
            ->where('ta.teacher = :teacher')
            ->andWhere('ta.status = :status')
            ->setParameter('teacher', $teacherId)
            ->setParameter('status', 'ACTIVE');

        return array_column($qb->getQuery()->getArrayResult(), 'academicClass');
    }

    private function applyStudentFilter(QueryBuilder $qb, ?Uuid $studentId): void
    {
        if (null === $studentId) {
            return;
        }

        $qb->andWhere('lr.student = :student')
            ->setParameter('student', $studentId);
    }

    private function applyLeaveTypeFilter(QueryBuilder $qb, ?Uuid $leaveTypeId): void
    {
        if (null === $leaveTypeId) {
            return;
        }

        $qb->andWhere('lr.leaveType = :leaveType')
            ->setParameter('leaveType', $leaveTypeId);
    }

    private function applyStatusFilter(QueryBuilder $qb, ?string $status): void
    {
        if (null === $status || '' === $status) {
            return;
        }

        $qb->andWhere('lr.status = :status')
            ->setParameter('status', $status);
    }

    private function applyDateFilter(QueryBuilder $qb, ?\DateTimeImmutable $startDate, ?\DateTimeImmutable $endDate): void
    {
        if ($startDate !== null) {
            $qb->andWhere('lr.startDate >= :startDate')
                ->setParameter('startDate', $startDate);
        }

        if ($endDate !== null) {
            $qb->andWhere('lr.endDate <= :endDate')
                ->setParameter('endDate', $endDate);
        }
    }

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['createdAt', 'startDate', 'endDate', 'status'];

        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'createdAt';
        }

        $sortOrder = mb_strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $qb->addOrderBy('lr.' . $sortBy, $sortOrder);
    }

    private function getTotalCount(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(lr.id)');
        $countQb->resetDQLPart('orderBy');

        return (int) $countQb->getQuery()->getSingleScalarResult();
    }
}
