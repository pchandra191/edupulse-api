<?php
declare(strict_types=1);

namespace App\Service\AttendanceModule;

use App\DTO\AttendanceModule\AcademicClassReferenceDTO;
use App\DTO\AttendanceModule\AcademicYearReferenceDTO;
use App\DTO\AttendanceModule\AttendanceCalendarItemDTO;
use App\DTO\AttendanceModule\AttendanceFilterRequest;
use App\DTO\AttendanceModule\AttendanceRecordBulkRequestDTO;
use App\DTO\AttendanceModule\AttendanceRecordResponseDTO;
use App\DTO\AttendanceModule\AttendanceRecordUpdateRequestDTO;
use App\DTO\AttendanceModule\AttendanceSummaryDTO;
use App\DTO\AttendanceModule\InstituteReferenceDTO;
use App\DTO\AttendanceModule\SectionReferenceDTO;
use App\DTO\AttendanceModule\StudentAttendanceCalendarDTO;
use App\DTO\AttendanceModule\StudentAttendanceSummaryDTO;
use App\DTO\AttendanceModule\StudentCheckInRequestDTO;
use App\DTO\AttendanceModule\StudentReferenceDTO;
use App\DTO\AttendanceModule\UserReferenceDTO;
use App\DTO\Response\AttendanceRecordListResponse;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\AttendanceRecord;
use App\Entity\EnrollmentSection;
use App\Entity\Holiday;
use App\Entity\Institute;
use App\Entity\Section;
use App\Entity\StudentEnrollment;
use App\Entity\TeacherAssignment;
use App\Entity\User;
use App\Enum\AttendanceStatus;
use App\Enum\EnrollmentSectionStatus;
use App\Enum\UserRole;
use App\Exception\AuthenticationException;
use App\Repository\AttendanceRecordRepository;
use App\Repository\HolidayRepository;
use App\Repository\LeaveRequestRepository;
use App\Repository\SectionRepository;
use App\Repository\StudentEnrollmentRepository;
use App\Repository\TeacherAssignmentRepository;
use DateTimeInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

readonly class AttendanceService
{
    private const WORKING_DAYS_DEFAULT = 'MON-FRI';

    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private AttendanceRecordRepository $attendanceRecordRepository,
        private HolidayRepository $holidayRepository,
        private StudentEnrollmentRepository $studentEnrollmentRepository,
        private LeaveRequestRepository $leaveRequestRepository,
        private TeacherAssignmentRepository $teacherAssignmentRepository,
        private SectionRepository $sectionRepository,
        private LoggerInterface $logger,
        private AttendanceDateTimeProvider $dateTimeProvider,
    ) {
    }

    public function getAttendanceRoster(
        ?Uuid $instituteId,
        string $academicYearId,
        string $academicClassId,
        string $sectionId,
        string $date
    ): array {
        $user = $this->getCurrentUser();
        $this->validateAcademicContext($instituteId, $academicYearId, $academicClassId, $sectionId);

        $dateObj = $this->parseDate($date);
        $academicYear = $this->entityManager->getReference(AcademicYear::class, Uuid::fromString($academicYearId));
        $academicClass = $this->entityManager->getRepository(AcademicClass::class)->find($academicClassId);
        $section = $this->entityManager->getRepository(Section::class)->find($sectionId);

        $this->validateTeacherAuthorization($user, $academicClass, $section, $academicYear, $instituteId);

        $students = $this->getEnrolledStudents($academicYearId, $academicClassId, $sectionId, $instituteId);

        $attendanceByStudent = $this->getAttendanceMapForDate(
            Uuid::fromString($academicYearId),
            Uuid::fromString($academicClassId),
            Uuid::fromString($sectionId),
            $dateObj
        );

        $roster = [];
        foreach ($students as $student) {
            $existing = $attendanceByStudent[$student['id']->toRfc4122()] ?? null;

            $roster[] = [
                'studentId' => (string) $student['id'],
                'studentName' => trim(($student['firstName'] ?? '') . ' ' . ($student['lastName'] ?? '')),
                'rollNumber' => $student['rollNumber'],
                'status' => $existing ? $existing->getStatusValue() : null,
                'remarks' => $existing ? $existing->getRemarks() : null,
                'recordId' => $existing ? (string) $existing->getId() : null,
            ];
        }

        $isHoliday = $this->holidayRepository->isHoliday($dateObj, $instituteId, Uuid::fromString($academicYearId));

        return [
            'academicYearId' => $academicYearId,
            'classId' => $academicClassId,
            'sectionId' => $sectionId,
            'date' => $date,
            'isHoliday' => $isHoliday,
            'roster' => $roster,
        ];
    }

    public function bulkMarkAttendance(
        ?Uuid $instituteId,
        AttendanceRecordBulkRequestDTO $request
    ): array {
        $user = $this->getCurrentUser();

        $academicYear = $this->entityManager->getRepository(AcademicYear::class)->find($request->academicYearId);
        if (!$academicYear instanceof AcademicYear) {
            throw new \InvalidArgumentException('Academic year not found.');
        }

        $academicClass = $this->entityManager->getRepository(AcademicClass::class)->find($request->classId);
        if (!$academicClass instanceof AcademicClass) {
            throw new \InvalidArgumentException('Academic class not found.');
        }

        $section = $this->entityManager->getRepository(Section::class)->find($request->sectionId);
        if (!$section instanceof Section) {
            throw new \InvalidArgumentException('Section not found.');
        }

        $this->validateTeacherAuthorization($user, $academicClass, $section, $academicYear, $instituteId);

        $dateObj = $this->parseDate($request->attendanceDate);

        try {
            $this->entityManager->beginTransaction();

            $results = [];
            foreach ($request->records as $recordDto) {
                $student = $this->entityManager->getRepository(User::class)->find($recordDto->studentId);
                if (!$student instanceof User) {
                    throw new \InvalidArgumentException('Student not found: ' . $recordDto->studentId);
                }

                $existing = $this->attendanceRecordRepository->findByStudentAndDate(
                    $student->getId(),
                    $academicClass->getId(),
                    $dateObj,
                    $section->getId()
                );

                $status = AttendanceStatus::from($recordDto->status);

                if ($existing instanceof AttendanceRecord) {
                    $existing->setStatus($status);
                    $existing->setRemarks($recordDto->remarks);
                    $existing->setSource(AttendanceRecord::SOURCE_BULK);
                    $existing->setMarkedBy($user);
                    $existing->setMarkedAt(new \DateTimeImmutable());
                    $existing->setInstitute($academicClass->getInstitute());
                    $this->entityManager->persist($existing);
                    $record = $existing;
                } else {
                    $record = new AttendanceRecord();
                    $record->setInstitute($academicClass->getInstitute());
                    $record->setAcademicYear($academicYear);
                    $record->setStudent($student);
                    $record->setAcademicClass($academicClass);
                    $record->setSection($section);
                    $record->setAttendanceDate($dateObj);
                    $record->setStatus($status);
                    $record->setRemarks($recordDto->remarks);
                    $record->setSource(AttendanceRecord::SOURCE_BULK);
                    $record->setMarkedBy($user);
                    $record->setMarkedAt(new \DateTimeImmutable());
                    $this->entityManager->persist($record);
                }

                $results[] = $this->mapToResponse($record);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            return $results;
        } catch (UniqueConstraintViolationException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Unique constraint violation during bulk attendance marking.', [
                'class_id' => $request->classId,
                'date' => $request->attendanceDate,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Duplicate attendance record detected. Please refresh and try again.', previous: $e);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to mark bulk attendance.', [
                'class_id' => $request->classId,
                'date' => $request->attendanceDate,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to mark attendance. Please try again.', previous: $e);
        }
    }

    public function studentCheckIn(?Uuid $instituteId, StudentCheckInRequestDTO $location): AttendanceRecordResponseDTO
    {
        $user = $this->getCurrentUser();

        if (!in_array(UserRole::Student->value, $user->getRoles(), true)) {
            throw new AuthenticationException('Only students can perform self check-in.');
        }

        $student = $user;
        $enrollment = $this->studentEnrollmentRepository->findActiveEnrollmentByStudent($student->getId());

        if (!$enrollment instanceof StudentEnrollment) {
            throw new AuthenticationException('Student has no active enrollment.');
        }

        $academicYear = $enrollment->getAcademicYear();
        $academicClass = $enrollment->getAcademicClass();
        $section = $this->resolveEnrollmentSection($enrollment);

        $studentInstitute = $student->getInstitute();

        if ($instituteId !== null) {
            if ($studentInstitute === null || !$studentInstitute->getId()->equals($instituteId)) {
                throw new AuthenticationException('Enrollment does not belong to your institute.');
            }
        }

        $today = $this->dateTimeProvider->currentBusinessDateForInstitute($studentInstitute);

        $existing = $this->attendanceRecordRepository->findByStudentAndDate(
            $student->getId(),
            $academicClass->getId(),
            $today,
            $section !== null ? $section->getId() : null
        );

        if ($existing instanceof AttendanceRecord) {
            return $this->mapToResponse($existing);
        }

        if ($studentInstitute !== null && $this->holidayRepository->isHoliday($today, $studentInstitute->getId(), $academicYear->getId())) {
            throw new AuthenticationException('Attendance cannot be marked on a holiday.');
        }

        $leaveRequests = $this->leaveRequestRepository->findApprovedByStudentAndDateRange(
            $student->getId(),
            $today,
            $today,
            $studentInstitute !== null ? $studentInstitute->getId() : null
        );

        if (count($leaveRequests) > 0) {
            throw new AuthenticationException('Attendance cannot be marked while on approved leave for today.');
        }

        try {
            $this->entityManager->beginTransaction();

            $record = new AttendanceRecord();
            $record->setInstitute($academicClass->getInstitute());
            $record->setAcademicYear($academicYear);
            $record->setStudent($student);
            $record->setAcademicClass($academicClass);
            $record->setSection($section);
            $record->setAttendanceDate($today);
            $record->setStatus(AttendanceStatus::Present);
            $record->setSource(AttendanceRecord::SOURCE_SYSTEM);
            $record->setMarkedBy($student);
            $record->setMarkedAt(new \DateTimeImmutable());
            $record->setLatitude($location->latitude);
            $record->setLongitude($location->longitude);
            $record->setAccuracyMeters($location->accuracyMeters);
            $record->setLocationCapturedAt(new \DateTimeImmutable());

            $this->entityManager->persist($record);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($record);
        } catch (UniqueConstraintViolationException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $existing = $this->attendanceRecordRepository->findByStudentAndDate(
                $student->getId(),
                $academicClass->getId(),
                $today,
                $section !== null ? $section->getId() : null
            );

            if ($existing instanceof AttendanceRecord) {
                return $this->mapToResponse($existing);
            }

            throw new \App\Common\Exception\BusinessException('Failed to record attendance. Please try again.', previous: $e);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to process student check-in.', [
                'student_id' => (string) $student->getId(),
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to record attendance. Please try again.', previous: $e);
        }
    }

    private function resolveEnrollmentSection(StudentEnrollment $enrollment): ?Section
    {
        $sections = $enrollment->getEnrollmentSections();

        if (count($sections) === 0) {
            return null;
        }

        foreach ($sections as $enrollmentSection) {
            if ($enrollmentSection instanceof EnrollmentSection && $enrollmentSection->getStatus()->value === EnrollmentSectionStatus::Active->value) {
                return $enrollmentSection->getSection();
            }
        }

        $first = $sections->first();
        if ($first instanceof EnrollmentSection) {
            return $first->getSection();
        }

        return null;
    }

    public function updateAttendanceRecord(
        ?Uuid $instituteId,
        string $recordId,
        AttendanceRecordUpdateRequestDTO $request
    ): AttendanceRecordResponseDTO {
        $user = $this->getCurrentUser();

        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            throw new AuthenticationException('Students are not authorized to update attendance records.');
        }

        $record = $this->attendanceRecordRepository->findById(Uuid::fromString($recordId), $instituteId);

        if (!$record instanceof AttendanceRecord) {
            throw new AuthenticationException('Attendance record not found.');
        }

        $this->validateRecordAccess($user, $record, $instituteId);

        $status = AttendanceStatus::from($request->status);
        $record->setStatus($status);
        $record->setRemarks($request->remarks);
        $record->setSource(AttendanceRecord::SOURCE_MANUAL);
        $record->setMarkedBy($user);
        $record->setMarkedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        return $this->mapToResponse($record);
    }

    public function getAttendanceRecord(?Uuid $instituteId, string $recordId): AttendanceRecordResponseDTO
    {
        $user = $this->getCurrentUser();

        $record = $this->attendanceRecordRepository->findById(Uuid::fromString($recordId), $instituteId);

        if (!$record instanceof AttendanceRecord) {
            throw new AuthenticationException('Attendance record not found.');
        }

        $this->validateRecordAccess($user, $record, $instituteId);

        return $this->mapToResponse($record);
    }

    public function getStudentAttendance(
        ?Uuid $instituteId,
        AttendanceFilterRequest $filter
    ): AttendanceRecordListResponse {
        $user = $this->getCurrentUser();

        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            if ($filter->studentId === null || !Uuid::fromString($filter->studentId)->equals($user->getId())) {
                throw new AuthenticationException('You can only view your own attendance.');
            }
        }

        if ($filter->studentId !== null) {
            $studentUser = $this->entityManager->getRepository(User::class)->find($filter->studentId);
            if (!$studentUser instanceof User) {
                throw new \InvalidArgumentException('Student not found.');
            }

            $this->validateStudentViewAccess($user, $studentUser, $instituteId);
        }

        $result = $this->attendanceRecordRepository->findPaginated(
            $instituteId,
            $filter->studentId ? Uuid::fromString($filter->studentId) : null,
            $filter->academicYearId ? Uuid::fromString($filter->academicYearId) : null,
            $filter->classId ? Uuid::fromString($filter->classId) : null,
            $filter->sectionId ? Uuid::fromString($filter->sectionId) : null,
            $filter->startDate ? $this->parseDate($filter->startDate) : null,
            $filter->endDate ? $this->parseDate($filter->endDate) : null,
            $filter->status,
            $filter->page,
            $filter->limit,
            $filter->sortBy,
            $filter->sortOrder,
        );

        $items = array_map(
            fn (AttendanceRecord $record) => $this->mapToResponse($record),
            $result['items'],
        );

        return new AttendanceRecordListResponse(
            items: $items,
            pagination: $result['pagination'],
        );
    }

    public function getStudentAttendanceSummary(
        ?Uuid $instituteId,
        string $studentId,
        ?string $academicYearId = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): StudentAttendanceSummaryDTO {
        $user = $this->getCurrentUser();

        $studentUser = $this->entityManager->getRepository(User::class)->find($studentId);
        if (!$studentUser instanceof User) {
            throw new AuthenticationException('Student not found.');
        }

        $this->validateStudentViewAccess($user, $studentUser, $instituteId);

        $academicYear = null;
        if ($academicYearId !== null) {
            $academicYear = $this->entityManager->getRepository(AcademicYear::class)->find($academicYearId);
            if (!$academicYear instanceof AcademicYear) {
                throw new \InvalidArgumentException('Academic year not found.');
            }

            if ($instituteId !== null && !$academicYear->getInstitute()->getId()->equals($instituteId)) {
                throw new \InvalidArgumentException('Academic year does not belong to your institute.');
            }
        }

        $summary = $this->calculateAttendanceSummary(
            $studentUser->getId(),
            $academicYear ? $academicYear->getId() : null,
            $startDate,
            $endDate
        );

        $monthlyStats = null;
        if ($startDate !== null && $endDate !== null) {
            $monthlyStats = $this->calculateMonthlyStats(
                $studentUser->getId(),
                $startDate,
                $endDate
            );
        }

        return new StudentAttendanceSummaryDTO(
            student: new StudentReferenceDTO(
                id: (string) $studentUser->getId(),
                name: trim(($studentUser->getFirstName() ?? '') . ' ' . ($studentUser->getLastName() ?? '')),
                email: $studentUser->getEmail(),
                rollNumber: null,
            ),
            summary: $summary,
            monthlyStats: $monthlyStats,
        );
    }

    public function getStudentAttendanceCalendar(
        ?Uuid $instituteId,
        string $studentId,
        int $year,
        int $month
    ): StudentAttendanceCalendarDTO {
        $user = $this->getCurrentUser();

        $studentUser = $this->entityManager->getRepository(User::class)->find($studentId);
        if (!$studentUser instanceof User) {
            throw new AuthenticationException('Student not found.');
        }

        $this->validateStudentViewAccess($user, $studentUser, $instituteId);

        if ($instituteId !== null && $studentUser->getInstitute()?->getId() !== null) {
            if (!$studentUser->getInstitute()->getId()->equals($instituteId)) {
                throw new \InvalidArgumentException('Student does not belong to your institute.');
            }
        }

        $enrollment = $this->studentEnrollmentRepository->findActiveEnrollmentByStudent($studentUser->getId());

        $startDate = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $endDate = $startDate->modify('last day of this month');

        $academicYearId = $enrollment ? $enrollment->getAcademicYear()->getId() : null;
        $classId = $enrollment ? $enrollment->getAcademicClass()->getId() : null;
        $sectionId = null;
        if ($enrollment && count($enrollment->getEnrollmentSections()) > 0) {
            $section = $enrollment->getEnrollmentSections()->first();
            if ($section instanceof EnrollmentSection) {
                $sectionId = $section->getSection()->getId();
            }
        }

        $records = $this->attendanceRecordRepository->findByStudentDateRange(
            $studentUser->getId(),
            $startDate,
            $endDate,
            $academicYearId,
            $classId,
            $sectionId
        );

        $recordMap = [];
        foreach ($records as $record) {
            $recordMap[$record->getAttendanceDate()->format('Y-m-d')] = $record;
        }

        $holidays = $this->holidayRepository->findHolidaysInDateRange($startDate, $endDate, $instituteId, $academicYearId);
        $holidayDates = [];
        foreach ($holidays as $holiday) {
            $holidayDates[$holiday->getHolidayDate()->format('Y-m-d')] = $holiday->getName();
        }

        $items = [];
        $current = $startDate;
        while ($current <= $endDate) {
            $dateStr = $current->format('Y-m-d');
            $dayOfWeek = (int) $current->format('N');
            $isWeekend = $dayOfWeek === 6 || $dayOfWeek === 7;
            $isHoliday = isset($holidayDates[$dateStr]);

            $status = 'NOT_MARKED';
            $remarks = null;

            if ($isHoliday) {
                $status = AttendanceStatus::Holiday->value;
                $remarks = $holidayDates[$dateStr];
            } elseif (!$isWeekend) {
                if (isset($recordMap[$dateStr])) {
                    $status = $recordMap[$dateStr]->getStatusValue();
                    $remarks = $recordMap[$dateStr]->getRemarks();
                }
            } else {
                $status = 'WEEKEND';
            }

            $items[] = new AttendanceCalendarItemDTO(
                date: $dateStr,
                dayName: $current->format('l'),
                status: $status,
                isWorkingDay: !$isWeekend && !$isHoliday,
                isHoliday: $isHoliday,
                remarks: $remarks,
            );

            $current = $current->modify('+1 day');
        }

        return new StudentAttendanceCalendarDTO(
            year: $year,
            month: $month,
            items: $items,
        );
    }

    public function getStudentAttendanceHistory(
        ?Uuid $instituteId,
        string $studentId,
        ?string $academicYearId = null,
        ?string $startDate = null,
        ?string $endDate = null,
        int $page = 1,
        int $limit = 10,
        string $sortBy = 'attendanceDate',
        string $sortOrder = 'DESC'
    ): AttendanceRecordListResponse {
        $user = $this->getCurrentUser();

        $studentUser = $this->entityManager->getRepository(User::class)->find($studentId);
        if (!$studentUser instanceof User) {
            throw new AuthenticationException('Student not found.');
        }

        $this->validateStudentViewAccess($user, $studentUser, $instituteId);

        $filter = new AttendanceFilterRequest(
            studentId: (string) $studentUser->getId(),
            academicYearId: $academicYearId,
            startDate: $startDate,
            endDate: $endDate,
            page: max(1, $page),
            limit: max(1, min(100, $limit)),
            sortBy: $sortBy,
            sortOrder: $sortOrder,
        );

        return $this->getStudentAttendance($instituteId, $filter);
    }

    private function getCurrentUser(): User
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return $user;
    }

    private function validateAcademicContext(
        ?Uuid $instituteId,
        string $academicYearId,
        string $academicClassId,
        string $sectionId
    ): void {
        $academicYear = $this->entityManager->getRepository(AcademicYear::class)->find($academicYearId);
        if (!$academicYear instanceof AcademicYear) {
            throw new \InvalidArgumentException('Academic year not found.');
        }

        if ($instituteId !== null && !$academicYear->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Academic year does not belong to your institute.');
        }

        $academicClass = $this->entityManager->getRepository(AcademicClass::class)->find($academicClassId);
        if (!$academicClass instanceof AcademicClass) {
            throw new \InvalidArgumentException('Academic class not found.');
        }

        if ($academicClass->getAcademicYear()->getId() != $academicYear->getId()) {
            throw new \InvalidArgumentException('Class does not belong to the selected academic year.');
        }

        if ($instituteId !== null && !$academicClass->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Class does not belong to your institute.');
        }

        $section = $this->entityManager->getRepository(Section::class)->find($sectionId);
        if (!$section instanceof Section) {
            throw new \InvalidArgumentException('Section not found.');
        }

        if (!$section->getAcademicClass()->getId()->equals($academicClass->getId())) {
            throw new \InvalidArgumentException('Section does not belong to the selected class.');
        }
    }

    private function validateTeacherAuthorization(
        User $user,
        AcademicClass $academicClass,
        Section $section,
        AcademicYear $academicYear,
        ?Uuid $instituteId
    ): void {
        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            throw new AuthenticationException('Students are not authorized to mark attendance.');
        }

        if (in_array(UserRole::SuperAdmin->value, $user->getRoles(), true)) {
            return;
        }

        if (in_array(UserRole::Institute->value, $user->getRoles(), true)) {
            return;
        }

        if (!in_array(UserRole::Teacher->value, $user->getRoles(), true)) {
            throw new AuthenticationException('Unauthorized role for attendance operations.');
        }

        $hasAssignment = $this->teacherAssignmentRepository->createQueryBuilder('ta')
            ->select('COUNT(ta.id)')
            ->where('ta.teacher = :teacher')
            ->andWhere('ta.academicYear = :academicYear')
            ->andWhere('ta.academicClass = :academicClass')
            ->andWhere('ta.status = :status')
            ->setParameter('teacher', $user->getId())
            ->setParameter('academicYear', $academicYear->getId())
            ->setParameter('academicClass', $academicClass->getId())
            ->setParameter('status', 'ACTIVE')
            ->getQuery()
            ->getSingleScalarResult();

        if ((int) $hasAssignment === 0) {
            $hasClassAssignment = $this->teacherAssignmentRepository->createQueryBuilder('ta')
                ->select('COUNT(ta.id)')
                ->where('ta.teacher = :teacher')
                ->andWhere('ta.academicYear = :academicYear')
                ->andWhere('ta.academicClass = :academicClass')
                ->andWhere('ta.section IS NULL')
                ->andWhere('ta.status = :status')
                ->setParameter('teacher', $user->getId())
                ->setParameter('academicYear', $academicYear->getId())
                ->setParameter('academicClass', $academicClass->getId())
                ->setParameter('status', 'ACTIVE')
                ->getQuery()
                ->getSingleScalarResult();

            if ((int) $hasClassAssignment === 0) {
                throw new AuthenticationException('You are not authorized to mark attendance for this class/section.');
            }

            return;
        }

        $hasSectionAssignment = $this->teacherAssignmentRepository->createQueryBuilder('ta')
            ->select('COUNT(ta.id)')
            ->where('ta.teacher = :teacher')
            ->andWhere('ta.academicYear = :academicYear')
            ->andWhere('ta.academicClass = :academicClass')
            ->andWhere('ta.section = :section')
            ->andWhere('ta.status = :status')
            ->setParameter('teacher', $user->getId())
            ->setParameter('academicYear', $academicYear->getId())
            ->setParameter('academicClass', $academicClass->getId())
            ->setParameter('section', $section->getId())
            ->setParameter('status', 'ACTIVE')
            ->getQuery()
            ->getSingleScalarResult();

        if ((int) $hasSectionAssignment > 0) {
            return;
        }
    }

    private function validateRecordAccess(User $user, AttendanceRecord $record, ?Uuid $instituteId): void
    {
        if (in_array(UserRole::SuperAdmin->value, $user->getRoles(), true)) {
            return;
        }

        if ($instituteId !== null && $record->getInstitute()?->getId() !== null) {
            if (!$record->getInstitute()->getId()->equals($instituteId)) {
                throw new AuthenticationException('Access denied to this attendance record.');
            }
        }

        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            if (!$record->getStudent()->getId()->equals($user->getId())) {
                throw new AuthenticationException('You can only view your own attendance.');
            }
        }

        if (in_array(UserRole::Teacher->value, $user->getRoles(), true) && !in_array(UserRole::Institute->value, $user->getRoles(), true)) {
            $studentUser = $record->getStudent();
            $academicYear = $record->getAcademicYear();
            $academicClass = $record->getAcademicClass();
            $section = $record->getSection();

            $hasAssignment = $this->teacherAssignmentRepository->findActiveByTeacherAndClassAndSection(
                $user->getId(),
                $academicYear->getId(),
                $academicClass->getId(),
                $section ? $section->getId() : null
            );

            if (!$hasAssignment) {
                throw new AuthenticationException('You are not authorized to view this attendance record.');
            }
        }
    }

    private function validateStudentViewAccess(User $user, User $studentUser, ?Uuid $instituteId): void
    {
        if (in_array(UserRole::SuperAdmin->value, $user->getRoles(), true)) {
            return;
        }

        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            if (!$studentUser->getId()->equals($user->getId())) {
                throw new AuthenticationException('You can only view your own attendance.');
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
                throw new AuthenticationException('You are not authorized to view attendance for this student.');
            }
        }
    }

    private function getEnrolledStudents(string $academicYearId, string $classId, string $sectionId, ?Uuid $instituteId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u.id, u.firstName, u.lastName, u.email, en.rollNumber, sec.id as sectionId')
            ->from(StudentEnrollment::class, 'en')
            ->innerJoin('en.student', 'u')
            ->innerJoin('en.enrollmentSections', 'ens')
            ->innerJoin('ens.section', 'sec')
            ->innerJoin('en.academicYear', 'ay')
            ->innerJoin('en.academicClass', 'ac')
            ->where('en.academicYear = :academicYear')
            ->andWhere('en.academicClass = :academicClass')
            ->andWhere('ens.section = :section')
            ->andWhere('en.status = :enStatus')
            ->andWhere('ens.status = :ensStatus')
            ->setParameter('academicYear', Uuid::fromString($academicYearId))
            ->setParameter('academicClass', Uuid::fromString($classId))
            ->setParameter('section', Uuid::fromString($sectionId))
            ->setParameter('enStatus', 'ACTIVE')
            ->setParameter('ensStatus', 'ACTIVE');

        if ($instituteId !== null) {
            $qb->andWhere('ac.institute = :institute')
                ->setParameter('institute', $instituteId);
        }

        return $qb->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    private function getAttendanceMapForDate(
        Uuid $academicYearId,
        Uuid $classId,
        Uuid $sectionId,
        \DateTimeImmutable $date
    ): array {
        $records = $this->attendanceRecordRepository->findRosterForDate(
            $academicYearId,
            $classId,
            $sectionId,
            $date
        );

        $map = [];
        foreach ($records as $record) {
            $map[$record->getStudent()->getId()->toRfc4122()] = $record;
        }

        return $map;
    }

    private function calculateAttendanceSummary(
        Uuid $studentId,
        ?Uuid $academicYearId,
        ?string $startDate,
        ?string $endDate
    ): AttendanceSummaryDTO {
        $start = $startDate ? $this->parseDate($startDate) : null;
        $end = $endDate ? $this->parseDate($endDate) : null;

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

        $presentForPercentage = $counts[AttendanceStatus::Present->value] + $counts[AttendanceStatus::Late->value] + $counts[AttendanceStatus::HalfDay->value];

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

    private function calculateMonthlyStats(Uuid $studentId, string $startDate, string $endDate): array
    {
        $start = $this->parseDate($startDate);
        $end = $this->parseDate($endDate);

        $stats = [];
        $current = $start;

        while ($current <= $end) {
            $year = (int) $current->format('Y');
            $month = (int) $current->format('m');

            $results = $this->attendanceRecordRepository->getMonthlyAttendanceSummary(
                $studentId,
                $year,
                $month
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
            $presentForPercentage = $counts[AttendanceStatus::Present->value] + $counts[AttendanceStatus::Late->value] + $counts[AttendanceStatus::HalfDay->value];
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

    private function parseDate(string $date): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$parsed) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $date);
            if (!$parsed) {
                throw new \InvalidArgumentException('Invalid date format. Expected YYYY-MM-DD.');
            }
            $parsed = $parsed->setTime(0, 0, 0);
        }

        return $parsed->setTime(0, 0, 0);
    }

    private function mapToResponse(AttendanceRecord $record): AttendanceRecordResponseDTO
    {
        $student = $record->getStudent();
        $academicYear = $record->getAcademicYear();
        $academicClass = $record->getAcademicClass();
        $section = $record->getSection();
        $institute = $record->getInstitute();
        $markedBy = $record->getMarkedBy();

        $rollNumber = null;
        if ($student && $record->getAcademicClass()) {
            $enrollment = $this->studentEnrollmentRepository->createQueryBuilder('se')
                ->where('se.student = :student')
                ->andWhere('se.academicYear = :academicYear')
                ->andWhere('se.academicClass = :academicClass')
                ->setParameter('student', $student->getId())
                ->setParameter('academicYear', $academicYear->getId())
                ->setParameter('academicClass', $academicClass->getId())
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($enrollment instanceof StudentEnrollment) {
                $rollNumber = $enrollment->getRollNumber();
            }
        }

        return new AttendanceRecordResponseDTO(
            id: (string) $record->getId(),
            institute: $institute ? new InstituteReferenceDTO(
                id: (string) $institute->getId(),
                name: $institute->getName(),
            ) : null,
            student: new StudentReferenceDTO(
                id: (string) $student->getId(),
                name: trim(($student->getFirstName() ?? '') . ' ' . ($student->getLastName() ?? '')),
                email: $student->getEmail(),
                rollNumber: $rollNumber,
            ),
            academicYear: new AcademicYearReferenceDTO(
                id: (string) $academicYear->getId(),
                name: $academicYear->getName(),
            ),
            academicClass: new AcademicClassReferenceDTO(
                id: (string) $academicClass->getId(),
                name: $academicClass->getName(),
                code: $academicClass->getCode(),
                startDate: $academicClass->getStartDate()?->format(DateTimeInterface::ATOM),
                endDate: $academicClass->getEndDate()?->format(DateTimeInterface::ATOM),
            ),
            section: $section ? new SectionReferenceDTO(
                id: (string) $section->getId(),
                name: $section->getName(),
                code: $section->getCode(),
            ) : null,
            attendanceDate: $record->getAttendanceDate()->format('Y-m-d'),
            status: $record->getStatusValue(),
            markedBy: $markedBy ? new UserReferenceDTO(
                id: (string) $markedBy->getId(),
                name: trim(($markedBy->getFirstName() ?? '') . ' ' . ($markedBy->getLastName() ?? '')),
                email: $markedBy->getEmail(),
            ) : null,
            markedAt: $record->getMarkedAt()?->format(DateTimeInterface::ATOM),
            remarks: $record->getRemarks(),
            source: $record->getSource(),
            latitude: $record->getLatitude(),
            longitude: $record->getLongitude(),
            accuracyMeters: $record->getAccuracyMeters(),
            locationCapturedAt: $record->getLocationCapturedAt()?->format(DateTimeInterface::ATOM),
            createdAt: $record->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $record->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }
}
