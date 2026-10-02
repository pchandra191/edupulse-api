<?php
declare(strict_types=1);

namespace App\Service\AttendanceModule;

use App\DTO\AttendanceModule\AttendanceAlertDTO;
use App\DTO\AttendanceModule\AttendanceSummaryDTO;
use App\DTO\AttendanceModule\DashboardStatsDTO;
use App\DTO\AttendanceModule\LowAttendanceStudentDTO;
use App\DTO\AttendanceModule\MonthlyTrendDTO;
use App\DTO\AttendanceModule\StudentAttendanceDashboardDTO;
use App\DTO\AttendanceModule\SubjectAttendanceDTO;
use App\DTO\AttendanceModule\SubjectAttendanceItemDTO;
use App\DTO\AttendanceModule\TodayAttendanceDTO;
use App\Entity\AttendancePolicy;
use App\Entity\AttendanceRecord;
use App\Entity\EnrollmentSection;
use App\Entity\LeaveRequest;
use App\Entity\StudentEnrollment;
use App\Entity\TeacherAssignment;
use App\Enum\AttendanceStatus;
use App\Enum\LeaveRequestStatus;
use App\Enum\UserRole;
use App\Exception\AuthenticationException;
use App\Repository\AttendancePolicyRepository;
use App\Repository\AttendanceRecordRepository;
use App\Repository\HolidayRepository;
use App\Repository\LeaveRequestRepository;
use App\Service\AttendanceModule\AttendanceDateTimeProvider;
use App\Repository\StudentEnrollmentRepository;
use App\Repository\TeacherAssignmentRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

readonly class AttendanceReportingService
{
    public const WORKING_DAYS_DEFAULT = 'MON-FRI';

    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private AttendanceRecordRepository $attendanceRecordRepository,
        private HolidayRepository $holidayRepository,
        private StudentEnrollmentRepository $studentEnrollmentRepository,
        private TeacherAssignmentRepository $teacherAssignmentRepository,
        private LeaveRequestRepository $leaveRequestRepository,
        private AttendancePolicyRepository $attendancePolicyRepository,
        private AttendanceDateTimeProvider $dateTimeProvider,
    ) {
    }

    public function getDashboardStats(?Uuid $instituteId, string $academicYearId, string $classId, ?string $sectionId = null): DashboardStatsDTO
    {
        $user = $this->getCurrentUser();

        $academicYear = $this->entityManager->getRepository(\App\Entity\AcademicYear::class)->find($academicYearId);
        $academicClass = $this->entityManager->getRepository(\App\Entity\AcademicClass::class)->find($classId);

        if (!$academicYear instanceof \App\Entity\AcademicYear) {
            throw new \InvalidArgumentException('Academic year not found.');
        }

        if (!$academicClass instanceof \App\Entity\AcademicClass) {
            throw new \InvalidArgumentException('Academic class not found.');
        }

        $this->validateTeacherAuthorization($user, $academicClass, $academicYear, $instituteId);

        $today = $this->dateTimeProvider->currentBusinessDate($instituteId);

        $todayAttendance = $this->getTodayAttendance($instituteId, $academicYearId, $classId, $sectionId, $today);

        $lowAttendanceStudents = $this->getLowAttendanceStudents($instituteId, $academicYearId, $classId);

        $pendingLeaveRequests = $this->getPendingLeaveRequestCount($instituteId, $academicYearId, $classId, $sectionId);

        $monthlyTrend = $this->getMonthlyTrend($instituteId, $academicYearId, $classId, $today->format('Y'), $today->format('m'));

        $overallPercentage = $this->getClassOverallAttendancePercentage($instituteId, $academicYearId, $classId, $sectionId);

        return new DashboardStatsDTO(
            overallAttendancePercentage: $overallPercentage,
            todayAttendance: $todayAttendance,
            studentsAbsentToday: [],
            studentsOnLeaveToday: [],
            lowAttendanceStudents: array_map(fn (LowAttendanceStudentDTO $dto) => $dto, $lowAttendanceStudents),
            pendingLeaveRequests: $pendingLeaveRequests,
            monthlyAttendanceTrend: array_map(fn (MonthlyTrendDTO $dto) => $dto, $monthlyTrend),
        );
    }

    public function getStudentDashboard(
        ?Uuid $instituteId,
        string $studentId,
        ?string $academicYearId = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): StudentAttendanceDashboardDTO {
        $user = $this->getCurrentUser();

        $studentUser = $this->entityManager->getRepository(\App\Entity\User::class)->find($studentId);
        if (!$studentUser instanceof \App\Entity\User) {
            throw new AuthenticationException('Student not found.');
        }

        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            if (!Uuid::fromString($studentId)->equals($user->getId())) {
                throw new AuthenticationException('You can only view your own attendance.');
            }
        } else {
            $this->validateStudentViewAccess($user, $studentUser, $instituteId);
        }

        $enrollment = $this->studentEnrollmentRepository->findActiveEnrollmentByStudent($studentUser->getId());

        $academicYear = null;
        $academicClass = null;
        $section = null;

        if ($enrollment instanceof StudentEnrollment) {
            $academicYear = $enrollment->getAcademicYear();
            $academicClass = $enrollment->getAcademicClass();

            if (count($enrollment->getEnrollmentSections()) > 0) {
                $enrollmentSection = $enrollment->getEnrollmentSections()->first();
                if ($enrollmentSection instanceof EnrollmentSection) {
                    $section = $enrollmentSection->getSection();
                }
            }
        }

        $today = $this->dateTimeProvider->currentBusinessDateForInstitute($studentUser->getInstitute());
        $todayAttendance = $this->getTodayAttendance(
            $instituteId,
            $academicYear ? (string) $academicYear->getId() : null,
            $academicClass ? (string) $academicClass->getId() : null,
            $section ? (string) $section->getId() : null,
            $today,
            $studentUser->getId()
        );

        $overallSummary = null;
        $monthlySummary = null;
        $monthlyStats = null;
        $subjectAttendance = null;

        if ($academicYear instanceof \App\Entity\AcademicYear) {
            // Resolve attendance window: use class startDate/endDate if available,
            // otherwise fall back to academic year dates
            $attendanceWindow = $this->resolveAttendanceWindow(
                $studentUser,
                $academicYear,
                $academicClass ?? null
            );
            $yearStart = $attendanceWindow['start'];
            $yearEnd = $attendanceWindow['end'];

            $overallSummary = $this->calculateStudentAttendanceSummary(
                $studentUser->getId(),
                $academicYear->getId(),
                $yearStart->format('Y-m-d'),
                $yearEnd->format('Y-m-d')
            );

            $currentMonthStart = $today->modify('first day of this month')->setTime(0, 0, 0);
            $currentMonthEnd = $today->modify('last day of this month')->setTime(23, 59, 59);

            $monthlySummary = $this->calculateStudentAttendanceSummary(
                $studentUser->getId(),
                $academicYear->getId(),
                $currentMonthStart->format('Y-m-d'),
                $currentMonthEnd->format('Y-m-d')
            );

            $monthlyStats = $this->calculateMonthlyStats(
                $studentUser->getId(),
                $academicYear->getId(),
                $yearStart->format('Y-m-d'),
                $yearEnd->format('Y-m-d')
            );
        } else {
            $startDateStr = $today->modify('-30 days')->format('Y-m-d');
            $endDateStr = $today->format('Y-m-d');

            $startDate ??= $startDateStr;
            $endDate ??= $endDateStr;

            $overallSummary = $this->calculateStudentAttendanceSummary(
                $studentUser->getId(),
                null,
                $startDate,
                $endDate
            );

            $monthlyStats = $this->calculateMonthlyStats(
                $studentUser->getId(),
                null,
                $startDate,
                $endDate
            );
        }

        $pendingLeaveRequests = 0;
        if ($academicYear instanceof \App\Entity\AcademicYear) {
            $pendingLeaveRequests = $this->leaveRequestRepository->countPendingForStudent(
                $studentUser->getId(),
                $instituteId
            );
        }

        $subjectAttendance = $this->getStudentSubjectAttendance(
            $instituteId,
            $studentId,
            $academicYear instanceof \App\Entity\AcademicYear ? (string) $academicYear->getId() : null
        );

        return new StudentAttendanceDashboardDTO(
            todayAttendance: $todayAttendance,
            summary: $overallSummary,
            monthlySummary: $monthlySummary,
            monthlyStats: $monthlyStats ?? [],
            pendingLeaveRequests: $pendingLeaveRequests,
            subjectAttendance: $subjectAttendance,
        );
    }

    public function getStudentSubjectAttendance(
        ?Uuid $instituteId,
        string $studentId,
        ?string $academicYearId = null
    ): SubjectAttendanceDTO {
        $user = $this->getCurrentUser();

        $studentUser = $this->entityManager->getRepository(\App\Entity\User::class)->find($studentId);
        if (!$studentUser instanceof \App\Entity\User) {
            throw new AuthenticationException('Student not found.');
        }

        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            if (!Uuid::fromString($studentId)->equals($user->getId())) {
                throw new AuthenticationException('You can only view your own attendance.');
            }
        } else {
            $this->validateStudentViewAccess($user, $studentUser, $instituteId);
        }

        $academicYear = null;

        if ($academicYearId !== null) {
            $academicYear = $this->entityManager->getRepository(\App\Entity\AcademicYear::class)->find($academicYearId);
        } else {
            $enrollment = $this->studentEnrollmentRepository->findActiveEnrollmentByStudent($studentUser->getId());
            if ($enrollment instanceof \App\Entity\StudentEnrollment) {
                $academicYear = $enrollment->getAcademicYear();
            }
        }

        if (!$academicYear instanceof \App\Entity\AcademicYear) {
            return new SubjectAttendanceDTO([]);
        }

        if ($instituteId !== null && !$academicYear->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Academic year does not belong to your institute.');
        }

        $subjectResults = $this->attendanceRecordRepository->getSubjectWiseAttendance(
            $studentUser->getId(),
            $academicYear->getId(),
            $instituteId
        );

        $items = [];

        foreach ($subjectResults as $row) {
            $subjectId = $row['subjectId'] ? (string) $row['subjectId'] : null;
            $subjectName = $row['subjectName'] ? (string) $row['subjectName'] : null;
            $subjectCode = $row['subjectCode'] ? (string) $row['subjectCode'] : null;
            $className = $row['className'] ? (string) $row['className'] : null;
            $classCode = $row['classCode'] ? (string) $row['classCode'] : null;
            $sectionName = $row['sectionName'] ? (string) $row['sectionName'] : null;
            $sectionCode = $row['sectionCode'] ? (string) $row['sectionCode'] : null;
            $classId = $row['classId'] ? (string) $row['classId'] : null;
            $sectionId = $row['sectionId'] ? (string) $row['sectionId'] : null;

            $totalClasses = (int) $this->attendanceRecordRepository->getSubjectTotalClasses(
                $studentUser->getId(),
                Uuid::fromString($subjectId),
                $academicYear->getId(),
                $classId !== null ? Uuid::fromString($classId) : null,
                $sectionId !== null ? Uuid::fromString($sectionId) : null,
                $instituteId
            );

            $statusCounts = $this->attendanceRecordRepository->getSubjectAttendanceDetail(
                $studentUser->getId(),
                Uuid::fromString($subjectId),
                $academicYear->getId(),
                $classId !== null ? Uuid::fromString($classId) : null,
                $sectionId !== null ? Uuid::fromString($sectionId) : null,
                $instituteId
            );

            $present = 0;
            $absent = 0;
            $late = 0;
            $onLeave = 0;

            foreach ($statusCounts as $statRow) {
                $status = $statRow['status'];
                $cnt = (int) $statRow['cnt'];
                switch ($status) {
                    case AttendanceStatus::Present->value:
                    case AttendanceStatus::HalfDay->value:
                        $present += $cnt;
                        break;
                    case AttendanceStatus::Absent->value:
                        $absent += $cnt;
                        break;
                    case AttendanceStatus::Late->value:
                        $late += $cnt;
                        break;
                    case AttendanceStatus::OnLeave->value:
                        $onLeave += $cnt;
                        break;
                }
            }

            $percentage = $totalClasses > 0
                ? round(($present / $totalClasses) * 100, 2)
                : 0.0;

            $items[] = new SubjectAttendanceItemDTO(
                subjectId: $subjectId,
                subjectName: $subjectName,
                subjectCode: $subjectCode,
                className: $className,
                classCode: $classCode,
                sectionName: $sectionName,
                sectionCode: $sectionCode,
                totalClasses: $totalClasses,
                present: $present,
                absent: $absent,
                late: $late,
                onLeave: $onLeave,
                percentage: $percentage,
            );
        }

        return new SubjectAttendanceDTO($items);
    }

    public function getStudentAlerts(
        ?Uuid $instituteId,
        string $studentId,
        ?string $academicYearId = null
    ): array {
        $user = $this->getCurrentUser();

        $studentUser = $this->entityManager->getRepository(\App\Entity\User::class)->find($studentId);
        if (!$studentUser instanceof \App\Entity\User) {
            throw new AuthenticationException('Student not found.');
        }

        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            if (!Uuid::fromString($studentId)->equals($user->getId())) {
                throw new AuthenticationException('You can only view your own attendance.');
            }
        } else {
            $this->validateStudentViewAccess($user, $studentUser, $instituteId);
        }

        $academicYear = null;

        if ($academicYearId !== null) {
            $academicYear = $this->entityManager->getRepository(\App\Entity\AcademicYear::class)->find($academicYearId);
        } else {
            $enrollment = $this->studentEnrollmentRepository->findActiveEnrollmentByStudent($studentUser->getId());
            if ($enrollment instanceof \App\Entity\StudentEnrollment) {
                $academicYear = $enrollment->getAcademicYear();
            }
        }

        if (!$academicYear instanceof \App\Entity\AcademicYear) {
            return [];
        }

        if ($instituteId !== null && !$academicYear->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Academic year does not belong to your institute.');
        }

        // Find student's academic class for attendance window resolution
        $studentEnrollment = $this->studentEnrollmentRepository->findActiveEnrollmentByStudent($studentUser->getId());
        $studentClass = $studentEnrollment instanceof \App\Entity\StudentEnrollment
            ? $studentEnrollment->getAcademicClass()
            : null;

        $policy = $this->attendancePolicyRepository->findActiveByInstituteAndYear(
            $instituteId,
            $academicYear->getId()
        );

        $requiredPercentage = $policy instanceof AttendancePolicy
            ? $policy->getMinimumAttendancePercentage()
            : 75;

        $alerts = [];

        $subjectAttendance = $this->getStudentSubjectAttendance($instituteId, $studentId, $academicYearId);

        foreach ($subjectAttendance->items as $subject) {
            if ($subject->percentage < $requiredPercentage) {
                $severity = $subject->percentage < ($requiredPercentage - 10) ? 'HIGH' : 'WARNING';
                $alerts[] = new AttendanceAlertDTO(
                    type: 'LOW_ATTENDANCE',
                    severity: $severity,
                    subjectId: $subject->subjectId,
                    subjectName: $subject->subjectName,
                    attendancePercentage: $subject->percentage,
                    requiredPercentage: $requiredPercentage,
                    message: sprintf(
                        'Your attendance in %s is %s%%, below the required %s%%.',
                        $subject->subjectName,
                        $subject->percentage,
                        $requiredPercentage
                    ),
                );
            }
        }

        $window = $this->resolveAttendanceWindow($studentUser, $academicYear, $studentClass);
        $summary = $this->calculateStudentAttendanceSummary(
            $studentUser->getId(),
            $academicYear->getId(),
            $window['start']->format('Y-m-d'),
            $window['end']->format('Y-m-d')
        );

        if ($summary->attendancePercentage < $requiredPercentage) {
            $severity = $summary->attendancePercentage < ($requiredPercentage - 10) ? 'HIGH' : 'WARNING';
            $alerts[] = new AttendanceAlertDTO(
                type: 'LOW_ATTENDANCE',
                severity: $severity,
                subjectId: null,
                subjectName: null,
                attendancePercentage: $summary->attendancePercentage,
                requiredPercentage: $requiredPercentage,
                message: sprintf(
                    'Your overall attendance is %s%%, below the required %s%%.',
                    $summary->attendancePercentage,
                    $requiredPercentage
                ),
            );
        }

        $today = $this->dateTimeProvider->currentBusinessDate($instituteId);
        $recentAbsences = $this->attendanceRecordRepository->findRecentAbsences(
            $studentUser->getId(),
            $academicYear->getId(),
            $today,
            7,
            $instituteId
        );

        if (count($recentAbsences) >= 3) {
            $alerts[] = new AttendanceAlertDTO(
                type: 'CONSECUTIVE_ABSENCE',
                severity: 'HIGH',
                subjectId: null,
                subjectName: null,
                attendancePercentage: null,
                requiredPercentage: null,
                message: sprintf(
                    'You have been absent for %d out of the last 7 days.',
                    count($recentAbsences)
                ),
            );
        }

        return $alerts;
    }

    public function getClassAttendanceReport(
        ?Uuid $instituteId,
        string $classId,
        ?string $sectionId = null,
        ?string $academicYearId = null,
        ?string $startDate = null,
        ?string $endDate = null,
        int $page = 1,
        int $limit = 10,
        string $sortBy = 'lastName',
        string $sortOrder = 'ASC'
    ): array {
        $user = $this->getCurrentUser();

        if ($academicYearId === null) {
            $academicYear = $this->studentEnrollmentRepository->findActiveEnrollmentByStudent($this->getCurrentUser()->getId())?->getAcademicYear();
        } else {
            $academicYear = $this->entityManager->getRepository(\App\Entity\AcademicYear::class)->find($academicYearId);
        }

        if (!$academicYear instanceof \App\Entity\AcademicYear) {
            throw new \InvalidArgumentException('Academic year not found.');
        }

        $academicClass = $this->entityManager->getRepository(\App\Entity\AcademicClass::class)->find($classId);

        if (!$academicClass instanceof \App\Entity\AcademicClass) {
            throw new \InvalidArgumentException('Academic class not found.');
        }

        $this->validateTeacherAuthorization($user, $academicClass, $academicYear, $instituteId);

        $summary = $this->attendanceRecordRepository->getClassAttendanceSummary(
            $academicClass->getId(),
            $academicYear->getId(),
            $sectionId ? Uuid::fromString($sectionId) : null,
            $instituteId
        );

        $totalWorkingDays = $this->calculateTotalWorkingDays(
            $instituteId,
            $academicYear ? $academicYear->getId() : null,
            $startDate,
            $endDate
        );

        $results = [];
        foreach ($summary as $row) {
            $studentId = $row['studentId'] instanceof Uuid ? $row['studentId'] : Uuid::fromString($row['studentId']);
            $student = $this->entityManager->getRepository(\App\Entity\User::class)->find($studentId);
            if (!$student instanceof \App\Entity\User) {
                continue;
            }

            $enrollment = $this->studentEnrollmentRepository->createQueryBuilder('se')
                ->where('se.student = :student')
                ->andWhere('se.academicYear = :academicYear')
                ->andWhere('se.academicClass = :academicClass')
                ->setParameter('student', $studentId)
                ->setParameter('academicYear', $academicYear->getId())
                ->setParameter('academicClass', $academicClass->getId())
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            $rollNumber = $enrollment instanceof StudentEnrollment ? $enrollment->getRollNumber() : null;

            $counts = [
                AttendanceStatus::Present->value => 0,
                AttendanceStatus::Absent->value => 0,
                AttendanceStatus::Late->value => 0,
                AttendanceStatus::HalfDay->value => 0,
                AttendanceStatus::OnLeave->value => 0,
                AttendanceStatus::Holiday->value => 0,
            ];

            for ($i = 2; $i < count($row); $i += 2) {
                if (isset($row[$i]) && isset($counts[$row[$i]])) {
                    $counts[$row[$i]] = $row[$i + 1];
                }
            }

            $totalRecords = array_sum($counts);
            $workingRecords = $totalRecords - $counts[AttendanceStatus::Holiday->value];
            $presentRecords = $counts[AttendanceStatus::Present->value] + $counts[AttendanceStatus::Late->value] + $counts[AttendanceStatus::HalfDay->value];

            $attendancePercentage = $workingRecords > 0
                ? round(($presentRecords / $workingRecords) * 100, 2)
                : 0.0;

            $results[] = [
                'studentId' => (string) $student->getId(),
                'studentName' => trim(($student->getFirstName() ?? '') . ' ' . ($student->getLastName() ?? '')),
                'rollNumber' => $rollNumber,
                'email' => $student->getEmail(),
                'totalWorkingDays' => $totalWorkingDays,
                'present' => $counts[AttendanceStatus::Present->value],
                'absent' => $counts[AttendanceStatus::Absent->value],
                'late' => $counts[AttendanceStatus::Late->value],
                'halfDay' => $counts[AttendanceStatus::HalfDay->value],
                'onLeave' => $counts[AttendanceStatus::OnLeave->value],
                'attendancePercentage' => $attendancePercentage,
                'isBelowMinimum' => $attendancePercentage < 75,
            ];
        }

        usort($results, function ($a, $b) use ($sortBy, $sortOrder) {
            $valA = $a[$sortBy] ?? '';
            $valB = $b[$sortBy] ?? '';
            $cmp = is_string($valA) ? strcasecmp($valA, $valB) : ($valA <=> $valB);
            return $sortOrder === 'ASC' ? $cmp : -$cmp;
        });

        $totalCount = count($results);
        $offset = ($page - 1) * $limit;
        $paginated = array_slice($results, $offset, $limit);

        return [
            'data' => $paginated,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'totalItems' => $totalCount,
                'totalPages' => $limit > 0 ? (int) ceil($totalCount / $limit) : 0,
            ],
        ];
    }

    public function getTodayAttendance(
        ?Uuid $instituteId,
        ?string $academicYearId,
        ?string $classId,
        ?string $sectionId,
        ?\DateTimeImmutable $date = null,
        ?Uuid $studentId = null
    ): TodayAttendanceDTO {
        $date = $date ?? $this->dateTimeProvider->currentBusinessDate($instituteId);

        $qb = $this->entityManager->createQueryBuilder()
            ->select('ar.status, COUNT(ar.id) as cnt')
            ->from(AttendanceRecord::class, 'ar')
            ->innerJoin('ar.academicYear', 'ay')
            ->where('ar.attendanceDate = :date')
            ->setParameter('date', $date->setTime(0, 0, 0));

        if ($studentId !== null) {
            $qb->andWhere('ar.student = :student')
                ->setParameter('student', $studentId);
        }

        if ($classId !== null) {
            $qb->andWhere('ar.academicClass = :classId')
                ->setParameter('classId', Uuid::fromString($classId));
        }

        if ($instituteId !== null) {
            $qb->innerJoin('ay.institute', 'i')
                ->andWhere('i.id = :institute')
                ->setParameter('institute', $instituteId);
        }

        if ($sectionId !== null) {
            $qb->andWhere('ar.section = :section')
                ->setParameter('section', Uuid::fromString($sectionId));
        }

        $results = $qb->groupBy('ar.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['status']] = (int) $row['cnt'];
        }

        $totalMarked = array_sum($counts);
        $notMarked = $this->calculateNotMarked($instituteId, $classId, $sectionId, $academicYearId, $date, $studentId);

        $onLeaveCount = $counts[AttendanceStatus::OnLeave->value] ?? 0;
        $leaveStudentCount = 0;

        if ($classId !== null && $instituteId !== null) {
            $leaveCountQb = $this->leaveRequestRepository->createQueryBuilder('lr')
                ->select('COUNT(DISTINCT lr.student)')
                ->innerJoin('lr.academicYear', 'ay')
                ->where('lr.startDate <= :date')
                ->andWhere('lr.endDate >= :date')
                ->andWhere('lr.status = :status')
                ->andWhere('lr.academicClass = :classId')
                ->andWhere('ay.institute = :institute')
                ->andWhere(
                    'NOT EXISTS (
                        SELECT 1 FROM ' . AttendanceRecord::class . ' ar2
                        WHERE ar2.student = lr.student
                        AND ar2.attendanceDate = :onLeaveDate
                        AND ar2.status = :onLeaveStatus
                    )'
                )
                ->setParameter('date', $date->setTime(0, 0, 0))
                ->setParameter('onLeaveDate', $date->setTime(0, 0, 0))
                ->setParameter('status', LeaveRequestStatus::Approved->value)
                ->setParameter('onLeaveStatus', AttendanceStatus::OnLeave->value)
                ->setParameter('classId', Uuid::fromString($classId))
                ->setParameter('institute', $instituteId);

            if ($academicYearId !== null) {
                $leaveCountQb->andWhere('lr.academicYear = :academicYear')
                    ->setParameter('academicYear', Uuid::fromString($academicYearId));
            }

            if ($sectionId !== null) {
                $leaveCountQb->andWhere('(lr.section = :section OR lr.section IS NULL)')
                    ->setParameter('section', $sectionId);
            }

            if ($studentId !== null) {
                $leaveCountQb->andWhere('lr.student = :student')
                    ->setParameter('student', $studentId);
            }

            $leaveStudentCount = (int) $leaveCountQb->getQuery()->getSingleScalarResult();
            $onLeaveCount += $leaveStudentCount;
            $notMarked = max(0, $notMarked - $leaveStudentCount);
        }

        return new TodayAttendanceDTO(
            totalMarked: $totalMarked,
            present: $counts[AttendanceStatus::Present->value] ?? 0,
            absent: $counts[AttendanceStatus::Absent->value] ?? 0,
            late: $counts[AttendanceStatus::Late->value] ?? 0,
            onLeave: $onLeaveCount,
            notMarked: $notMarked,
        );
    }

    private function calculateNotMarked(
        ?Uuid $instituteId,
        ?string $classId,
        ?string $sectionId,
        ?string $academicYearId,
        \DateTimeImmutable $date,
        ?Uuid $studentId = null
    ): int {
        if ($instituteId === null || $classId === null) {
            return 0;
        }

        $academicYearUuid = $academicYearId ? Uuid::fromString($academicYearId) : null;

        // Student-specific check: is this student marked today?
        if ($studentId !== null) {
            $studentMarked = (int) $this->entityManager->createQueryBuilder()
                ->select('COUNT(ar.id)')
                ->from(AttendanceRecord::class, 'ar')
                ->where('ar.student = :student')
                ->andWhere('ar.academicClass = :classId')
                ->andWhere('ar.attendanceDate = :date')
                ->setParameter('student', $studentId)
                ->setParameter('classId', Uuid::fromString($classId))
                ->setParameter('date', $date)
                ->getQuery()
                ->getSingleScalarResult();

            return $studentMarked > 0 ? 0 : 1;
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT se.student)')
            ->from(StudentEnrollment::class, 'se')
            ->innerJoin('se.academicClass', 'c')
            ->innerJoin('c.institute', 'i')
            ->leftJoin('se.enrollmentSections', 'ens')
            ->leftJoin('ens.section', 'sec')
            ->where('c.id = :classId')
            ->andWhere('i.id = :instituteId')
            ->andWhere('se.status = :status')
            ->setParameter('classId', Uuid::fromString($classId))
            ->setParameter('instituteId', $instituteId)
            ->setParameter('status', 'ACTIVE');

        if ($sectionId !== null) {
            $qb->andWhere('sec.id = :sectionId')
                ->setParameter('sectionId', $sectionId);
        }

        if ($academicYearUuid !== null) {
            $qb->andWhere('se.academicYear = :academicYear')
                ->setParameter('academicYear', $academicYearUuid);
        }

        $totalEnrolled = (int) $qb->getQuery()->getSingleScalarResult();

        $markedCount = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(ar.id)')
            ->from(AttendanceRecord::class, 'ar')
            ->where('ar.academicClass = :classId')
            ->andWhere('ar.attendanceDate = :date')
            ->andWhere('ar.institute = :instituteId')
            ->setParameter('classId', Uuid::fromString($classId))
            ->setParameter('date', $date)
            ->setParameter('instituteId', $instituteId)
            ->getQuery()
            ->getSingleScalarResult();

        return max(0, $totalEnrolled - $markedCount);
    }

    private function getLowAttendanceStudents(
        ?Uuid $instituteId,
        string $academicYearId,
        string $classId
    ): array {
        $results = $this->attendanceRecordRepository->getClassAttendanceSummary(
            Uuid::fromString($classId),
            Uuid::fromString($academicYearId),
            null,
            $instituteId
        );

        $totalWorkingDays = $this->calculateTotalWorkingDays($instituteId, Uuid::fromString($academicYearId), null, null);

        $lowAttendance = [];
        foreach ($results as $row) {
            $counts = [];
            for ($i = 2; $i < count($row); $i += 2) {
                if (isset($row[$i]) && isset($row[$i + 1])) {
                    if (isset($counts[$row[$i]])) {
                        $counts[$row[$i]] += $row[$i + 1];
                    } else {
                        $counts[$row[$i]] = $row[$i + 1];
                    }
                }
            }

            $presentRecords = ($counts[AttendanceStatus::Present->value] ?? 0)
                + ($counts[AttendanceStatus::Late->value] ?? 0)
                + ($counts[AttendanceStatus::HalfDay->value] ?? 0);

            $totalRecords = array_sum($counts);
            $workingRecords = $totalRecords - ($counts[AttendanceStatus::Holiday->value] ?? 0);

            $percentage = $workingRecords > 0 ? round(($presentRecords / $workingRecords) * 100, 2) : 0;

            if ($percentage < 75 && $totalRecords > 0) {
                $studentId = Uuid::fromString($row['studentId']);
                $student = $this->entityManager->getRepository(\App\Entity\User::class)->find($studentId);
                if (!$student instanceof \App\Entity\User) {
                    continue;
                }

                $enrollment = $this->studentEnrollmentRepository->createQueryBuilder('se')
                    ->where('se.student = :student')
                    ->andWhere('se.academicYear = :academicYear')
                    ->andWhere('se.academicClass = :academicClass')
                    ->setParameter('student', $studentId)
                    ->setParameter('academicYear', Uuid::fromString($academicYearId))
                    ->setParameter('academicClass', Uuid::fromString($classId))
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();

                $lowAttendance[] = new LowAttendanceStudentDTO(
                    studentId: (string) $student->getId(),
                    studentName: trim(($student->getFirstName() ?? '') . ' ' . ($student->getLastName() ?? '')),
                    rollNumber: $enrollment instanceof StudentEnrollment ? $enrollment->getRollNumber() : null,
                    email: $student->getEmail(),
                    attendancePercentage: $percentage,
                    className: $classId,
                    sectionName: null,
                );
            }
        }

        usort($lowAttendance, fn ($a, $b) => $a->attendancePercentage <=> $b->attendancePercentage);

        return $lowAttendance;
    }

    private function getPendingLeaveRequestCount(
        ?Uuid $instituteId,
        string $academicYearId,
        string $classId,
        ?string $sectionId
    ): int {
        $qb = $this->leaveRequestRepository->createQueryBuilder('lr')
            ->select('COUNT(lr.id)')
            ->innerJoin('lr.academicYear', 'ay')
            ->where('lr.status = :status')
            ->andWhere('lr.academicClass = :class')
            ->andWhere('lr.academicYear = :academicYear')
            ->setParameter('status', LeaveRequestStatus::Pending->value)
            ->setParameter('class', Uuid::fromString($classId))
            ->setParameter('academicYear', Uuid::fromString($academicYearId));

        if ($sectionId !== null) {
            $qb->andWhere('lr.section = :section')
                ->setParameter('section', Uuid::fromString($sectionId));
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function getMonthlyTrend(
        ?Uuid $instituteId,
        string $academicYearId,
        string $classId,
        string $year,
        string $month
    ): array {
        $results = $this->attendanceRecordRepository->getMonthlyTrend(
            $instituteId,
            Uuid::fromString($academicYearId),
            (int) $year
        );

        $byMonth = [];
        foreach ($results as $row) {
            $monthNum = (int) $row['month'];
            if (!isset($byMonth[$monthNum])) {
                $byMonth[$monthNum] = [
                    AttendanceStatus::Present->value => 0,
                    AttendanceStatus::Absent->value => 0,
                    AttendanceStatus::Late->value => 0,
                    AttendanceStatus::OnLeave->value => 0,
                ];
            }

            $byMonth[$monthNum][$row['status']] = $row['cnt'];
        }

        $trend = [];
        for ($m = 1; $m <= 12; $m++) {
            $counts = $byMonth[$m] ?? [
                AttendanceStatus::Present->value => 0,
                AttendanceStatus::Absent->value => 0,
                AttendanceStatus::Late->value => 0,
                AttendanceStatus::HalfDay->value => 0,
                AttendanceStatus::OnLeave->value => 0,
                AttendanceStatus::Holiday->value => 0,
            ];

            $totalDays = array_sum($counts);
            $workingDays = $totalDays;
            $presentForPercentage = ($counts[AttendanceStatus::Present->value] ?? 0)
                + ($counts[AttendanceStatus::Late->value] ?? 0)
                + ($counts[AttendanceStatus::HalfDay->value] ?? 0);

            $percentage = $workingDays > 0 ? round(($presentForPercentage / $workingDays) * 100, 2) : 0.0;

            $trend[] = new MonthlyTrendDTO(
                month: $m,
                monthName: (new \DateTimeImmutable())->setDate((int) $year, $m, 1)->format('F'),
                year: (int) $year,
                attendancePercentage: $percentage,
                presentCount: $counts[AttendanceStatus::Present->value],
                absentCount: $counts[AttendanceStatus::Absent->value],
                lateCount: $counts[AttendanceStatus::Late->value],
                onLeaveCount: $counts[AttendanceStatus::OnLeave->value],
            );
        }

        return $trend;
    }

    private function calculateStudentAttendanceSummary(
        Uuid $studentId,
        ?Uuid $academicYearId,
        ?string $startDate,
        ?string $endDate
    ): AttendanceSummaryDTO {
        $start = $startDate ? \DateTimeImmutable::createFromFormat('Y-m-d', $startDate) : null;
        $end = $endDate ? \DateTimeImmutable::createFromFormat('Y-m-d', $endDate) : null;

        // If no date range provided, fall back to existing behavior (count only explicit records)
        if ($start === null || $end === null) {
            return $this->calculateAttendanceSummaryFromRecords($studentId, $academicYearId, $start, $end);
        }

        return $this->calculateAttendanceSummaryWithAbsences($studentId, $academicYearId, $start, $end);
    }

    /**
     * Calculate attendance summary using the class-start-based attendance window.
     *
     * For every eligible working day in the attendance window:
     * - Past working day with no attendance record → ABSENT
     * - Today (current business date) with no record → NOT_MARKED (not counted as absent)
     * - Holiday → excluded
     * - Weekend → excluded
     * - Approved leave → LEAVE
     * - Explicit PRESENT/ABSENT/LATE/HALF_DAY → counted directly
     */
    private function calculateAttendanceSummaryWithAbsences(
        Uuid $studentId,
        ?Uuid $academicYearId,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end
    ): AttendanceSummaryDTO {
        $today = $this->dateTimeProvider->currentBusinessDate(null);

        // Fetch all explicit attendance records for the student in the date range
        $records = $this->attendanceRecordRepository->findByStudentDateRange(
            $studentId,
            $start,
            $end,
            $academicYearId,
            null,
            null
        );

        // Build a map of attendance_date => record for quick lookup
        $recordMap = [];
        foreach ($records as $record) {
            $dateKey = $record->getAttendanceDate()->format('Y-m-d');
            $recordMap[$dateKey] = $record;
        }

        // Fetch approved leave requests for this student in the date range
        $approvedLeaves = $this->leaveRequestRepository->findApprovedByStudentAndDateRange(
            $studentId,
            $start,
            $end
        );

        // Build a set of dates that have approved leave
        $leaveDateSet = [];
        foreach ($approvedLeaves as $leaveRequest) {
            $current = $leaveRequest->getStartDate();
            $leaveEnd = $leaveRequest->getEndDate();
            while ($current <= $leaveEnd) {
                $leaveDateSet[$current->format('Y-m-d')] = true;
                $current = $current->modify('+1 day');
            }
        }

        // Count statuses
        $counts = [
            AttendanceStatus::Present->value => 0,
            AttendanceStatus::Absent->value => 0,
            AttendanceStatus::Late->value => 0,
            AttendanceStatus::HalfDay->value => 0,
            AttendanceStatus::OnLeave->value => 0,
            AttendanceStatus::Holiday->value => 0,
        ];

        // Iterate through each day in the attendance window
        $current = clone $start;
        while ($current <= $end) {
            $dateKey = $current->format('Y-m-d');
            $dayOfWeek = (int) $current->format('N');

            // Check if there's an explicit attendance record (ALWAYS takes priority)
            if (isset($recordMap[$dateKey])) {
                $record = $recordMap[$dateKey];
                $status = $record->getStatusValue();
                if (isset($counts[$status])) {
                    $counts[$status]++;
                }
            } elseif ($this->holidayRepository->isHoliday($current, null, $academicYearId)) {
                // Holiday with no explicit record → count as holiday
                $counts[AttendanceStatus::Holiday->value]++;
            } elseif (isset($leaveDateSet[$dateKey])) {
                // Approved leave with no explicit record → count as ON_LEAVE
                $counts[AttendanceStatus::OnLeave->value]++;
            } elseif ($dayOfWeek >= 6) {
                // Weekend with no explicit record → skip
            } else {
                // No attendance record
                if ($current->format('Y-m-d') < $today->format('Y-m-d')) {
                    // Past working day with no record → ABSENT
                    $counts[AttendanceStatus::Absent->value]++;
                }
                // Today with no record → NOT_MARKED (not counted)
            }

            $current = $current->modify('+1 day');
        }

        $totalDays = array_sum($counts);
        $totalWorkingDays = $totalDays - $counts[AttendanceStatus::Holiday->value];
        $presentForPercentage = $counts[AttendanceStatus::Present->value]
            + $counts[AttendanceStatus::Late->value]
            + $counts[AttendanceStatus::HalfDay->value];

        $percentage = 0.0;
        if ($totalWorkingDays > 0) {
            $percentage = round(($presentForPercentage / $totalWorkingDays) * 100, 2);
        }

        return new AttendanceSummaryDTO(
            totalWorkingDays: $totalWorkingDays,
            present: $counts[AttendanceStatus::Present->value],
            absent: $counts[AttendanceStatus::Absent->value],
            late: $counts[AttendanceStatus::Late->value],
            halfDay: $counts[AttendanceStatus::HalfDay->value],
            onLeave: $counts[AttendanceStatus::OnLeave->value],
            holiday: $counts[AttendanceStatus::Holiday->value],
            attendancePercentage: $percentage,
            totalDays: $totalDays,
            presentDays: $counts[AttendanceStatus::Present->value],
            absentDays: $counts[AttendanceStatus::Absent->value],
            lateDays: $counts[AttendanceStatus::Late->value],
            leaveDays: $counts[AttendanceStatus::OnLeave->value],
        );
    }

    /**
     * Fallback: count only explicit attendance records (existing behavior).
     * Used when no date range is provided.
     */
    private function calculateAttendanceSummaryFromRecords(
        Uuid $studentId,
        ?Uuid $academicYearId,
        ?\DateTimeImmutable $start,
        ?\DateTimeImmutable $end
    ): AttendanceSummaryDTO {
        $results = $this->attendanceRecordRepository->getAttendanceSummary(
            $studentId,
            $academicYearId,
            $start,
            $end
        );

        $counts = [
            AttendanceStatus::Present->value => 0,
            AttendanceStatus::Absent->value => 0,
            AttendanceStatus::Late->value => 0,
            AttendanceStatus::HalfDay->value => 0,
            AttendanceStatus::OnLeave->value => 0,
            AttendanceStatus::Holiday->value => 0,
        ];

        foreach ($results as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = $row['cnt'];
            }
        }

        $totalDays = array_sum($counts);
        $totalWorkingDays = $totalDays - $counts[AttendanceStatus::Holiday->value];
        $presentForPercentage = $counts[AttendanceStatus::Present->value]
            + $counts[AttendanceStatus::Late->value]
            + $counts[AttendanceStatus::HalfDay->value];

        $percentage = 0.0;
        if ($totalWorkingDays > 0) {
            $percentage = round(($presentForPercentage / $totalWorkingDays) * 100, 2);
        }

        return new AttendanceSummaryDTO(
            totalWorkingDays: $totalWorkingDays,
            present: $counts[AttendanceStatus::Present->value],
            absent: $counts[AttendanceStatus::Absent->value],
            late: $counts[AttendanceStatus::Late->value],
            halfDay: $counts[AttendanceStatus::HalfDay->value],
            onLeave: $counts[AttendanceStatus::OnLeave->value],
            holiday: $counts[AttendanceStatus::Holiday->value],
            attendancePercentage: $percentage,
            totalDays: $totalDays,
            presentDays: $counts[AttendanceStatus::Present->value],
            absentDays: $counts[AttendanceStatus::Absent->value],
            lateDays: $counts[AttendanceStatus::Late->value],
            leaveDays: $counts[AttendanceStatus::OnLeave->value],
        );
    }

    private function calculateMonthlyStats(
        Uuid $studentId,
        ?Uuid $academicYearId,
        ?string $startDate,
        ?string $endDate
    ): array {
        $start = $startDate ? \DateTimeImmutable::createFromFormat('Y-m-d', $startDate) : new \DateTimeImmutable('first day of this month');
        $end = $endDate ? \DateTimeImmutable::createFromFormat('Y-m-d', $endDate) : new \DateTimeImmutable('last day of this month');

        $stats = [];
        $current = $start;

        while ($current <= $end) {
            $year = (int) $current->format('Y');
            $month = (int) $current->format('m');

            $results = $this->attendanceRecordRepository->getMonthlyAttendanceSummary(
                $studentId,
                $academicYearId,
                $month,
                $year
            );

            $counts = [
                AttendanceStatus::Present->value => 0,
                AttendanceStatus::Absent->value => 0,
                AttendanceStatus::Late->value => 0,
                AttendanceStatus::HalfDay->value => 0,
                AttendanceStatus::OnLeave->value => 0,
                AttendanceStatus::Holiday->value => 0,
            ];

            foreach ($results as $row) {
                if (isset($counts[$row['status']])) {
                    $counts[$row['status']] = $row['cnt'];
                }
            }

            $totalDays = array_sum($counts);
            $totalWorkingDays = $totalDays - $counts[AttendanceStatus::Holiday->value];
            $presentForPercentage = $counts[AttendanceStatus::Present->value]
                + $counts[AttendanceStatus::Late->value]
                + $counts[AttendanceStatus::HalfDay->value];
            $percentage = $totalWorkingDays > 0 ? round(($presentForPercentage / $totalWorkingDays) * 100, 2) : 0.0;

            $stats[] = [
                'month' => $month,
                'monthName' => $current->format('F'),
                'year' => $year,
                'totalWorkingDays' => $totalWorkingDays,
                'present' => $counts[AttendanceStatus::Present->value],
                'absent' => $counts[AttendanceStatus::Absent->value],
                'late' => $counts[AttendanceStatus::Late->value],
                'halfDay' => $counts[AttendanceStatus::HalfDay->value],
                'onLeave' => $counts[AttendanceStatus::OnLeave->value],
                'attendancePercentage' => $percentage,
            ];

            $current = $current->modify('first day of next month');
        }

        return $stats;
    }

    private function getClassOverallAttendancePercentage(
        ?Uuid $instituteId,
        string $academicYearId,
        string $classId,
        ?string $sectionId
    ): ?float {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('ar.status, COUNT(ar.id) as cnt')
            ->from(AttendanceRecord::class, 'ar')
            ->innerJoin('ar.academicYear', 'ay')
            ->where('ar.academicClass = :class')
            ->andWhere('ar.academicYear = :academicYear')
            ->setParameter('class', Uuid::fromString($classId))
            ->setParameter('academicYear', Uuid::fromString($academicYearId));

        if ($instituteId !== null) {
            $qb->innerJoin('ay.institute', 'i')
                ->andWhere('i.id = :institute')
                ->setParameter('institute', $instituteId);
        }

        if ($sectionId !== null) {
            $qb->andWhere('ar.section = :section')
                ->setParameter('section', Uuid::fromString($sectionId));
        }

        $qb->groupBy('ar.status');

        $results = $qb->getQuery()->getArrayResult();

        $counts = [
            AttendanceStatus::Present->value => 0,
            AttendanceStatus::Absent->value => 0,
            AttendanceStatus::Late->value => 0,
            AttendanceStatus::HalfDay->value => 0,
            AttendanceStatus::OnLeave->value => 0,
            AttendanceStatus::Holiday->value => 0,
        ];

        foreach ($results as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = $row['cnt'];
            }
        }

        $totalDays = array_sum($counts);
        $totalWorkingDays = $totalDays - $counts[AttendanceStatus::Holiday->value];
        $presentForPercentage = $counts[AttendanceStatus::Present->value]
            + $counts[AttendanceStatus::Late->value]
            + $counts[AttendanceStatus::HalfDay->value];

        if ($totalWorkingDays <= 0) {
            return null;
        }

        return round(($presentForPercentage / $totalWorkingDays) * 100, 2);
    }

    private function calculateTotalWorkingDays(
        ?Uuid $instituteId,
        ?Uuid $academicYearId,
        ?string $startDate,
        ?string $endDate
    ): int {
        $workingDays = 0;

        $today = $this->dateTimeProvider->currentBusinessDate($instituteId);
        $start = $startDate ? \DateTimeImmutable::createFromFormat('Y-m-d', $startDate) : $today;
        $end = $endDate ? \DateTimeImmutable::createFromFormat('Y-m-d', $endDate) : $today;

        $current = clone $start;
        while ($current <= $end) {
            $dayOfWeek = (int) $current->format('N');
            if ($dayOfWeek < 6) {
                if (!$this->holidayRepository->isHoliday($current, $instituteId, $academicYearId)) {
                    $workingDays++;
                }
            }
            $current = $current->modify('+1 day');
        }

        return $workingDays;
    }

    private function getCurrentUser(): \App\Entity\User
    {
        $user = $this->security->getUser();

        if (!$user instanceof \App\Entity\User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return $user;
    }

    /**
     * Resolve the attendance window for a student.
     *
     * Priority:
     * 1. If AcademicClass has startDate/endDate, use those (class-specific window)
     * 2. Otherwise fall back to AcademicYear dates
     *
     * The attendance start date is also constrained by the student's enrollment
     * effective date (admissionDate) if available.
     */
    private function resolveAttendanceWindow(
        \App\Entity\User $studentUser,
        \App\Entity\AcademicYear $academicYear,
        ?\App\Entity\AcademicClass $academicClass
    ): array {
        $today = $this->dateTimeProvider->currentBusinessDate($studentUser->getInstitute()?->getId());

        // Determine class-based window
        if ($academicClass instanceof \App\Entity\AcademicClass
            && $academicClass->getStartDate() !== null
            && $academicClass->getEndDate() !== null
        ) {
            $windowStart = $academicClass->getStartDate();
            $windowEnd = $academicClass->getEndDate();
        } else {
            // Fall back to academic year dates
            $windowStart = $academicYear->getStartDate();
            $windowEnd = $academicYear->getEndDate();
        }

        // Constrain by student enrollment effective date (admissionDate)
        $enrollment = $this->studentEnrollmentRepository->findActiveEnrollmentByStudent($studentUser->getId());
        if ($enrollment instanceof \App\Entity\StudentEnrollment && $enrollment->getAdmissionDate() !== null) {
            $admissionDate = $enrollment->getAdmissionDate();
            if ($admissionDate > $windowStart) {
                $windowStart = $admissionDate;
            }
        }

        // Constrain end date to current business date (don't count future days)
        if ($windowEnd > $today) {
            $windowEnd = $today;
        }

        return [
            'start' => $windowStart,
            'end' => $windowEnd,
        ];
    }

    private function validateTeacherAuthorization(
        \App\Entity\User $user,
        \App\Entity\AcademicClass $academicClass,
        \App\Entity\AcademicYear $academicYear,
        ?Uuid $instituteId
    ): void {
        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            throw new AuthenticationException('Students are not authorized to access attendance reports.');
        }

        if (in_array(UserRole::SuperAdmin->value, $user->getRoles(), true)) {
            return;
        }

        if (in_array(UserRole::Institute->value, $user->getRoles(), true)) {
            return;
        }

        if (!in_array(UserRole::Teacher->value, $user->getRoles(), true)) {
            throw new AuthenticationException('Unauthorized role for attendance report operations.');
        }
    }

    private function validateStudentViewAccess(\App\Entity\User $user, \App\Entity\User $studentUser, ?Uuid $instituteId): void
    {
        if (in_array(UserRole::SuperAdmin->value, $user->getRoles(), true)) {
            return;
        }

        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            if (!$studentUser->getId()->equals($user->getId())) {
                throw new AuthenticationException('Students can only view their own data.');
            }

            return;
        }

        if ($instituteId !== null && $studentUser->getInstitute()?->getId() !== null) {
            if (!$studentUser->getInstitute()->getId()->equals($instituteId)) {
                throw new \InvalidArgumentException('Student does not belong to your institute.');
            }
        }

        if (in_array(UserRole::Teacher->value, $user->getRoles(), true)) {
            $hasAccess = $this->teacherAssignmentRepository->createQueryBuilder('ta')
                ->select('COUNT(ta.id)')
                ->where('ta.teacher = :teacher')
                ->andWhere('ta.status = :status')
                ->setParameter('teacher', $user->getId())
                ->setParameter('status', 'ACTIVE')
                ->getQuery()
                ->getSingleScalarResult() > 0;

            if (!$hasAccess) {
                throw new AuthenticationException('You are not authorized to view this student\'s data.');
            }
        }
    }
}
