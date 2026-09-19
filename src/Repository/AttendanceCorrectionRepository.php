<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\AttendanceCorrection;
use App\Entity\AttendanceRecord;
use App\Entity\Institute;
use App\Entity\AcademicYear;
use App\Entity\User;
use App\Enum\AttendanceCorrectionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class AttendanceCorrectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AttendanceCorrection::class);
    }

    public function findById(Uuid $id, ?Uuid $instituteId = null): ?AttendanceCorrection
    {
        $qb = $this->createQueryBuilder('ac')
            ->where('ac.id = :id')
            ->setParameter('id', $id);

        if ($instituteId !== null) {
            $qb->andWhere('ac.institute = :institute')
                ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByAttendanceRecord(Uuid $attendanceRecordId): array
    {
        return $this->createQueryBuilder('ac')
            ->where('ac.attendanceRecord = :record')
            ->setParameter('record', $attendanceRecordId)
            ->orderBy('ac.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPaginated(
        ?Uuid $instituteId,
        ?Uuid $studentId,
        ?Uuid $attendanceRecordId,
        ?string $status,
        int $page,
        int $limit,
        string $sortBy,
        string $sortOrder
    ): array {
        $qb = $this->createQueryBuilder('ac')
            ->innerJoin('ac.student', 's')
            ->addSelect('s')
            ->innerJoin('ac.academicYear', 'ay')
            ->addSelect('ay')
            ->innerJoin('ac.attendanceRecord', 'ar')
            ->addSelect('ar');

        if ($instituteId !== null) {
            $qb->innerJoin('ay.institute', 'i')
                ->addSelect('i')
                ->where('i.id = :institute')
                ->setParameter('institute', $instituteId);
        }

        $this->applyStudentFilter($qb, $studentId);
        $this->applyAttendanceRecordFilter($qb, $attendanceRecordId);
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

    public function findPendingForStudent(Uuid $studentId, ?Uuid $instituteId = null): array
    {
        $qb = $this->createQueryBuilder('ac')
            ->innerJoin('ac.academicYear', 'ay')
            ->where('ac.student = :student')
            ->andWhere('ac.status = :status')
            ->setParameter('student', $studentId)
            ->setParameter('status', AttendanceCorrectionStatus::Pending->value);

        if ($instituteId !== null) {
            $qb->innerJoin('ay.institute', 'i')
                ->andWhere('i.id = :institute')
                ->setParameter('institute', $instituteId);
        }

        return $qb->orderBy('ac.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('ac')
            ->select('COUNT(ac.id)')
            ->where('ac.status = :status')
            ->setParameter('status', AttendanceCorrectionStatus::Pending->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function hasApprovedCorrectionForRecord(Uuid $attendanceRecordId): ?AttendanceCorrection
    {
        return $this->createQueryBuilder('ac')
            ->where('ac.attendanceRecord = :record')
            ->andWhere('ac.status = :status')
            ->setParameter('attendanceRecordId', $attendanceRecordId)
            ->setParameter('status', AttendanceCorrectionStatus::Approved->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function applyStudentFilter(QueryBuilder $qb, ?Uuid $studentId): void
    {
        if (null === $studentId) {
            return;
        }

        $qb->andWhere('ac.student = :student')
            ->setParameter('student', $studentId);
    }

    private function applyAttendanceRecordFilter(QueryBuilder $qb, ?Uuid $attendanceRecordId): void
    {
        if (null === $attendanceRecordId) {
            return;
        }

        $qb->andWhere('ac.attendanceRecord = :record')
            ->setParameter('record', $attendanceRecordId);
    }

    private function applyStatusFilter(QueryBuilder $qb, ?string $status): void
    {
        if (null === $status || '' === $status) {
            return;
        }

        $qb->andWhere('ac.status = :status')
            ->setParameter('status', $status);
    }

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['createdAt', 'requestedAt', 'approvedAt'];

        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'createdAt';
        }

        $sortOrder = mb_strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $qb->addOrderBy('ac.' . $sortBy, $sortOrder);
    }

    private function getTotalCount(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(ac.id)');
        $countQb->resetDQLPart('orderBy');

        return (int) $countQb->getQuery()->getSingleScalarResult();
    }
}
