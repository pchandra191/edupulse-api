<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\AttendanceRecord;
use App\Entity\Institute;
use App\Enum\AttendanceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class AttendanceRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AttendanceRecord::class);
    }

    public function findById(Uuid $id, ?Uuid $instituteId = null): ?AttendanceRecord
    {
        $qb = $this->createQueryBuilder('ar')
            ->where('ar.id = :id')
            ->setParameter('id', $id);

        if ($instituteId !== null) {
            $qb->andWhere('ar.institute = :institute')
                ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByStudentAndDate(
        Uuid $studentId,
        Uuid $academicClassId,
        \DateTimeImmutable $attendanceDate,
        ?Uuid $sectionId = null
    ): ?AttendanceRecord {
        $qb = $this->createQueryBuilder('ar')
            ->where('ar.student = :student')
            ->andWhere('ar.academicClass = :academicClass')
            ->andWhere('ar.attendanceDate = :attendanceDate')
            ->setParameter('student', $studentId)
            ->setParameter('academicClass', $academicClassId)
            ->setParameter('attendanceDate', $attendanceDate);

        if ($sectionId !== null) {
            $qb->andWhere('ar.section = :section')
                ->setParameter('section', $sectionId);
        } else {
            $qb->andWhere('ar.section IS NULL');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findRosterForDate(
        Uuid $academicYearId,
        Uuid $classId,
        ?Uuid $sectionId,
        \DateTimeImmutable $attendanceDate,
        ?Uuid $instituteId = null
    ): array {
        $qb = $this->createQueryBuilder('ar')
            ->innerJoin('ar.student', 's')
            ->addSelect('s')
            ->innerJoin('ar.academicClass', 'c')
            ->addSelect('c')
            ->innerJoin('ar.academicYear', 'ay')
            ->addSelect('ay')
            ->innerJoin('ay.institute', 'i')
            ->addSelect('i');

        $qb->where('ar.academicYear = :academicYear')
            ->andWhere('ar.academicClass = :class')
            ->andWhere('ar.attendanceDate = :attendanceDate')
            ->setParameter('academicYear', $academicYearId)
            ->setParameter('class', $classId)
            ->setParameter('attendanceDate', $attendanceDate);

        if ($sectionId !== null) {
            $qb->andWhere('ar.section = :section')
                ->setParameter('section', $sectionId);
        }

        if ($instituteId !== null) {
            $qb->andWhere('i.id = :institute')
                ->setParameter('institute', $instituteId);
        }

        return $qb->orderBy('s.lastName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findPaginated(
        ?Uuid $instituteId,
        ?Uuid $studentId,
        ?Uuid $academicYearId,
        ?Uuid $classId,
        ?Uuid $sectionId,
        ?\DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        ?string $status,
        int $page,
        int $limit,
        string $sortBy,
        string $sortOrder
    ): array {
        $qb = $this->createQueryBuilder('ar')
            ->innerJoin('ar.student', 's')
            ->addSelect('s')
            ->innerJoin('ar.academicClass', 'c')
            ->addSelect('c')
            ->innerJoin('ar.academicYear', 'ay')
            ->addSelect('ay')
            ->leftJoin('ar.section', 'sec')
            ->addSelect('sec');

        if ($instituteId !== null) {
            $qb->innerJoin('ay.institute', 'i')
                ->addSelect('i')
                ->where('i.id = :institute')
                ->setParameter('institute', $instituteId);
        }

        $this->applyStudentFilter($qb, $studentId);
        $this->applyAcademicYearFilter($qb, $academicYearId);
        $this->applyClassFilter($qb, $classId);
        $this->applySectionFilter($qb, $sectionId);
        $this->applyDateFilter($qb, $startDate, $endDate);
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

    public function getAttendanceSummary(
        Uuid $studentId,
        Uuid $academicYearId,
        ?\DateTimeImmutable $startDate = null,
        ?\DateTimeImmutable $endDate = null
    ): array {
        $qb = $this->createQueryBuilder('ar')
            ->select('ar.status, COUNT(ar.id) as cnt')
            ->where('ar.student = :student')
            ->andWhere('ar.academicYear = :academicYear')
            ->setParameter('student', $studentId)
            ->setParameter('academicYear', $academicYearId);

        if ($startDate !== null) {
            $qb->andWhere('ar.attendanceDate >= :startDate')
                ->setParameter('startDate', $startDate);
        }

        if ($endDate !== null) {
            $qb->andWhere('ar.attendanceDate <= :endDate')
                ->setParameter('endDate', $endDate);
        }

        $qb->groupBy('ar.status');

        return $qb->getQuery()->getArrayResult();
    }

    public function getMonthlyAttendanceSummary(
        Uuid $studentId,
        Uuid $academicYearId,
        int $month,
        int $year
    ): array {
        $qb = $this->createQueryBuilder('ar')
            ->select('ar.status, COUNT(ar.id) as cnt')
            ->where('ar.student = :student')
            ->andWhere('ar.academicYear = :academicYear')
            ->andWhere('Month(ar.attendanceDate) = :month')
            ->andWhere('Year(ar.attendanceDate) = :year')
            ->setParameter('student', $studentId)
            ->setParameter('academicYear', $academicYearId)
            ->setParameter('month', $month)
            ->setParameter('year', $year)
            ->groupBy('ar.status');

        return $qb->getQuery()->getArrayResult();
    }

    public function getMonthlyTrend(
        ?Uuid $instituteId,
        Uuid $academicYearId,
        int $year
    ): array {
        $qb = $this->createQueryBuilder('ar')
            ->innerJoin('ar.academicYear', 'ay')
            ->select('Month(ar.attendanceDate) as month, ar.status, COUNT(ar.id) as cnt')
            ->where('ar.academicYear = :academicYear')
            ->andWhere('Year(ar.attendanceDate) = :year')
            ->setParameter('academicYear', $academicYearId)
            ->setParameter('year', $year)
            ->groupBy('month, ar.status')
            ->orderBy('month', 'ASC');

        if ($instituteId !== null) {
            $qb->innerJoin('ay.institute', 'i')
                ->andWhere('i.id = :institute')
                ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getArrayResult();
    }

    public function getClassAttendanceSummary(
        Uuid $classId,
        Uuid $academicYearId,
        ?Uuid $sectionId = null,
        ?Uuid $instituteId = null
    ): array {
        $qb = $this->createQueryBuilder('ar')
            ->select('s.id AS studentId, s.firstName, s.lastName, ar.status, COUNT(ar.id) as cnt')
            ->innerJoin('ar.student', 's')
            ->where('ar.academicClass = :class')
            ->andWhere('ar.academicYear = :academicYear')
            ->setParameter('class', $classId)
            ->setParameter('academicYear', $academicYearId);

        if ($sectionId !== null) {
            $qb->andWhere('ar.section = :section')
                ->setParameter('section', $sectionId);
        }

        if ($instituteId !== null) {
            $qb->innerJoin('ar.academicYear', 'ay')
                ->innerJoin('ay.institute', 'i')
                ->andWhere('i.id = :institute')
                ->setParameter('institute', $instituteId);
        }

        $qb->groupBy('s.id, ar.status');

        return $qb->getQuery()->getArrayResult();
    }

    public function findStudentsWithAttendanceBySection(
        Uuid $sectionId,
        Uuid $academicYearId,
        \DateTimeImmutable $attendanceDate,
        ?Uuid $instituteId = null
    ): array {
        $qb = $this->createQueryBuilder('ar')
            ->innerJoin('ar.student', 's')
            ->addSelect('s')
            ->innerJoin('ar.section', 'sec')
            ->addSelect('sec')
            ->innerJoin('ar.academicYear', 'ay')
            ->addSelect('ay')
            ->where('ar.section = :section')
            ->andWhere('ar.academicYear = :academicYear')
            ->andWhere('ar.attendanceDate = :attendanceDate')
            ->setParameter('section', $sectionId)
            ->setParameter('academicYear', $academicYearId)
            ->setParameter('attendanceDate', $attendanceDate);

        if ($instituteId !== null) {
            $qb->innerJoin('ay.institute', 'i')
                ->andWhere('i.id = :institute')
                ->setParameter('institute', $instituteId);
        }

        return $qb->getQuery()->getResult();
    }

    public function findByStudentDateRange(
        Uuid $studentId,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        ?Uuid $academicYearId = null,
        ?Uuid $classId = null,
        ?Uuid $sectionId = null
    ): array {
        $qb = $this->createQueryBuilder('ar')
            ->innerJoin('ar.student', 's')
            ->addSelect('s')
            ->innerJoin('ar.academicYear', 'ay')
            ->addSelect('ay')
            ->leftJoin('ar.section', 'sec')
            ->addSelect('sec')
            ->where('ar.student = :student')
            ->andWhere('ar.attendanceDate >= :startDate')
            ->andWhere('ar.attendanceDate <= :endDate')
            ->setParameter('student', $studentId)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate);

        if ($academicYearId !== null) {
            $qb->andWhere('ar.academicYear = :academicYear')
                ->setParameter('academicYear', $academicYearId);
        }

        if ($classId !== null) {
            $qb->andWhere('ar.academicClass = :class')
                ->setParameter('class', $classId);
        }

        if ($sectionId !== null) {
            $qb->andWhere('ar.section = :section')
                ->setParameter('section', $sectionId);
        }

        return $qb->orderBy('ar.attendanceDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findRecentAbsences(
        Uuid $studentId,
        Uuid $academicYearId,
        \DateTimeImmutable $businessToday,
        int $days = 7,
        ?Uuid $instituteId = null
    ): array {
        $cutoffDate = $businessToday->modify(sprintf('-%d days', $days));

        $qb = $this->createQueryBuilder('ar')
            ->where('ar.student = :student')
            ->andWhere('ar.academicYear = :academicYear')
            ->andWhere('ar.attendanceDate >= :cutoff')
            ->andWhere('ar.status = :status')
            ->setParameter('student', $studentId)
            ->setParameter('academicYear', $academicYearId)
            ->setParameter('cutoff', $cutoffDate)
            ->setParameter('status', AttendanceStatus::Absent->value);

        if ($instituteId !== null) {
            $qb->andWhere('ar.institute = :institute')
                ->setParameter('institute', $instituteId);
        }

        return $qb->orderBy('ar.attendanceDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    private function applyStudentFilter(QueryBuilder $qb, ?Uuid $studentId): void
    {
        if (null === $studentId) {
            return;
        }

        $qb->andWhere('ar.student = :student')
            ->setParameter('student', $studentId);
    }

    private function applyAcademicYearFilter(QueryBuilder $qb, ?Uuid $academicYearId): void
    {
        if (null === $academicYearId) {
            return;
        }

        $qb->andWhere('ar.academicYear = :academicYear')
            ->setParameter('academicYear', $academicYearId);
    }

    private function applyClassFilter(QueryBuilder $qb, ?Uuid $classId): void
    {
        if (null === $classId) {
            return;
        }

        $qb->andWhere('ar.academicClass = :class')
            ->setParameter('class', $classId);
    }

    private function applySectionFilter(QueryBuilder $qb, ?Uuid $sectionId): void
    {
        if (null === $sectionId) {
            return;
        }

        $qb->andWhere('ar.section = :section')
            ->setParameter('section', $sectionId);
    }

    private function applyDateFilter(QueryBuilder $qb, ?\DateTimeImmutable $startDate, ?\DateTimeImmutable $endDate): void
    {
        if ($startDate !== null) {
            $qb->andWhere('ar.attendanceDate >= :startDate')
                ->setParameter('startDate', $startDate);
        }

        if ($endDate !== null) {
            $qb->andWhere('ar.attendanceDate <= :endDate')
                ->setParameter('endDate', $endDate);
        }
    }

    private function applyStatusFilter(QueryBuilder $qb, ?string $status): void
    {
        if (null === $status || '' === $status) {
            return;
        }

        $qb->andWhere('ar.status = :status')
            ->setParameter('status', $status);
    }

    private function applySorting(QueryBuilder $qb, string $sortBy, string $sortOrder): void
    {
        $allowedSortFields = ['attendanceDate', 'createdAt', 'student', 'status'];

        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'attendanceDate';
        }

        $sortOrder = mb_strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        if ($sortBy === 'student') {
            $qb->addOrderBy('s.lastName', $sortOrder)
                ->addOrderBy('s.firstName', $sortOrder);
        } else {
            $qb->addOrderBy('ar.' . $sortBy, $sortOrder);
        }
    }

    private function getTotalCount(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->select('COUNT(ar.id)');
        $countQb->resetDQLPart('groupBy');
        $countQb->resetDQLPart('orderBy');

        return (int) $countQb->getQuery()->getSingleScalarResult();
    }

    public function getSubjectWiseAttendance(
        Uuid $studentId,
        Uuid $academicYearId,
        ?Uuid $instituteId = null
    ): array {
        $qb = $this->createQueryBuilder('ar')
            ->select('s.id as subjectId, s.name as subjectName, s.code as subjectCode, c.id as classId, c.name as className, c.code as classCode, sec.id as sectionId, sec.name as sectionName, sec.code as sectionCode')
            ->innerJoin('ar.subject', 's')
            ->innerJoin('ar.academicClass', 'c')
            ->leftJoin('ar.section', 'sec')
            ->where('ar.student = :student')
            ->andWhere('ar.academicYear = :academicYear')
            ->andWhere('ar.subject IS NOT NULL')
            ->setParameter('student', $studentId)
            ->setParameter('academicYear', $academicYearId);

        if ($instituteId !== null) {
            $qb->andWhere('ar.institute = :institute')
                ->setParameter('institute', $instituteId);
        }

        $qb->groupBy('s.id, s.name, s.code, c.id, c.name, c.code, sec.id, sec.name, sec.code');

        return $qb->getQuery()->getArrayResult();
    }

    public function getSubjectAttendanceDetail(
        Uuid $studentId,
        Uuid $subjectId,
        Uuid $academicYearId,
        ?Uuid $classId = null,
        ?Uuid $sectionId = null,
        ?Uuid $instituteId = null
    ): array {
        $qb = $this->createQueryBuilder('ar')
            ->select('ar.status, COUNT(ar.id) as cnt')
            ->innerJoin('ar.subject', 's')
            ->where('ar.student = :student')
            ->andWhere('ar.subject = :subject')
            ->andWhere('ar.academicYear = :academicYear')
            ->setParameter('student', $studentId)
            ->setParameter('subject', $subjectId)
            ->setParameter('academicYear', $academicYearId);

        if ($classId !== null) {
            $qb->andWhere('ar.academicClass = :class')
                ->setParameter('class', $classId);
        }

        if ($sectionId !== null) {
            $qb->andWhere('ar.section = :section')
                ->setParameter('section', $sectionId);
        }

        if ($instituteId !== null) {
            $qb->andWhere('ar.institute = :institute')
                ->setParameter('institute', $instituteId);
        }

        $qb->groupBy('ar.status');

        return $qb->getQuery()->getArrayResult();
    }

    public function getSubjectTotalClasses(
        Uuid $studentId,
        Uuid $subjectId,
        Uuid $academicYearId,
        ?Uuid $classId = null,
        ?Uuid $sectionId = null,
        ?Uuid $instituteId = null
    ): int {
        $qb = $this->createQueryBuilder('ar')
            ->select('COUNT(ar.id)')
            ->innerJoin('ar.subject', 's')
            ->where('ar.student = :student')
            ->andWhere('ar.subject = :subject')
            ->andWhere('ar.academicYear = :academicYear')
            ->setParameter('student', $studentId)
            ->setParameter('subject', $subjectId)
            ->setParameter('academicYear', $academicYearId);

        if ($classId !== null) {
            $qb->andWhere('ar.academicClass = :class')
                ->setParameter('class', $classId);
        }

        if ($sectionId !== null) {
            $qb->andWhere('ar.section = :section')
                ->setParameter('section', $sectionId);
        }

        if ($instituteId !== null) {
            $qb->andWhere('ar.institute = :institute')
                ->setParameter('institute', $instituteId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
