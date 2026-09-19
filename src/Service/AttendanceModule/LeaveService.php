<?php
declare(strict_types=1);

namespace App\Service\AttendanceModule;

use App\DTO\AttendanceModule\AcademicClassReferenceDTO;
use App\DTO\AttendanceModule\AcademicYearReferenceDTO;
use App\DTO\AttendanceModule\AttendanceRecordBulkRequestDTO;
use App\DTO\AttendanceModule\AttendanceRecordUpdateRequestDTO;
use App\DTO\AttendanceModule\LeaveBalanceResponseDTO;
use App\DTO\AttendanceModule\LeaveRequestApproveRequestDTO;
use App\DTO\AttendanceModule\LeaveRequestCreateRequestDTO;
use App\DTO\AttendanceModule\LeaveRequestFilterRequest;
use App\DTO\AttendanceModule\LeaveRequestRejectRequestDTO;
use App\DTO\AttendanceModule\LeaveRequestResponseDTO;
use App\DTO\AttendanceModule\LeaveTypeCreateRequestDTO;
use App\DTO\AttendanceModule\LeaveTypeFilterRequest;
use App\DTO\AttendanceModule\LeaveTypeResponseDTO;
use App\DTO\AttendanceModule\LeaveTypeSummaryDTO;
use App\DTO\AttendanceModule\LeaveTypeUpdateRequestDTO;
use App\DTO\AttendanceModule\InstituteReferenceDTO;
use App\DTO\AttendanceModule\StudentReferenceDTO;
use App\DTO\AttendanceModule\StudentAttendanceSummaryDTO;
use App\DTO\AttendanceModule\AttendanceSummaryDTO;
use App\DTO\AttendanceModule\UserReferenceDTO;
use App\DTO\Response\LeaveBalanceListResponse;
use App\DTO\Response\LeaveRequestListResponse;
use App\DTO\Response\LeaveTypeListResponse;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\AttendanceRecord;
use App\Entity\EnrollmentSection;
use App\Entity\Institute;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Entity\LeaveType;
use App\Entity\Section;
use App\Entity\StudentEnrollment;
use App\Entity\User;
use App\Enum\AttendanceStatus;
use App\Enum\LeaveRequestStatus;
use App\Enum\UserRole;
use App\Exception\AuthenticationException;
use App\Repository\AttendanceRecordRepository;
use App\Repository\EnrollmentSectionRepository;
use App\Repository\HolidayRepository;
use App\Repository\LeaveBalanceRepository;
use App\Repository\LeaveRequestRepository;
use App\Repository\LeaveTypeRepository;
use App\Repository\StudentEnrollmentRepository;
use App\Repository\TeacherAssignmentRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

readonly class LeaveService
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private LeaveTypeRepository $leaveTypeRepository,
        private LeaveRequestRepository $leaveRequestRepository,
        private LeaveBalanceRepository $leaveBalanceRepository,
        private AttendanceRecordRepository $attendanceRecordRepository,
        private StudentEnrollmentRepository $studentEnrollmentRepository,
        private EnrollmentSectionRepository $enrollmentSectionRepository,
        private HolidayRepository $holidayRepository,
        private TeacherAssignmentRepository $teacherAssignmentRepository,
        private LoggerInterface $logger,
    ) {
    }

    // ==================== LEAVE TYPE MANAGEMENT ====================

    public function getLeaveTypes(?Uuid $instituteId, LeaveTypeFilterRequest $filter): LeaveTypeListResponse
    {
        $user = $this->security->getUser();
        $isStudent = $user instanceof User && in_array(UserRole::Student->value, $user->getRoles(), true);

        $queryFilter = $filter;

        if ($isStudent) {
            $queryFilter = new LeaveTypeFilterRequest(
                search: $filter->search,
                enabled: 'true',
                page: $filter->page,
                limit: $filter->limit,
                sortBy: $filter->sortBy,
                sortOrder: $filter->sortOrder,
            );
        }

        $result = $this->leaveTypeRepository->findPaginated(
            $instituteId,
            $queryFilter->search,
            $queryFilter->enabled,
            $queryFilter->page,
            $queryFilter->limit,
            $queryFilter->sortBy,
            $queryFilter->sortOrder,
        );

        $items = array_map(
            fn (LeaveType $leaveType) => $this->mapLeaveTypeToResponse($leaveType),
            $result['items'],
        );

        return new LeaveTypeListResponse(
            items: $items,
            pagination: $result['pagination'],
        );
    }

    public function getLeaveType(?Uuid $instituteId, string $id): LeaveTypeResponseDTO
    {
        $leaveType = $this->leaveTypeRepository->findById(Uuid::fromString($id), $instituteId);

        if (!$leaveType instanceof LeaveType) {
            throw new AuthenticationException('Leave type not found.');
        }

        return $this->mapLeaveTypeToResponse($leaveType);
    }

    public function createLeaveType(?Uuid $instituteId, LeaveTypeCreateRequestDTO $request): LeaveTypeResponseDTO
    {
        $this->validateManagementAccess();

        $leaveType = new LeaveType();
        $leaveType->setName($request->name);
        $leaveType->setCode($request->code);
        $leaveType->setDescription($request->description);
        $leaveType->setEnabled($request->enabled);
        $leaveType->setRequiresApproval($request->requiresApproval);
        $leaveType->setMaximumDays($request->maximumDays);
        $leaveType->setCarryForward($request->carryForward);

        if ($instituteId !== null) {
            $institute = $this->entityManager->getReference(Institute::class, $instituteId);
            $leaveType->setInstitute($institute);
        }

        if ($this->leaveTypeRepository->codeExists($request->code, $instituteId)) {
            throw new \App\Common\Exception\BusinessException('Leave type code already exists for this institute.');
        }

        try {
            $this->entityManager->beginTransaction();
            $this->entityManager->persist($leaveType);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            throw new \App\Common\Exception\BusinessException('Failed to create leave type. Please try again.', previous: $e);
        }

        return $this->mapLeaveTypeToResponse($leaveType);
    }

    public function updateLeaveType(?Uuid $instituteId, string $id, LeaveTypeUpdateRequestDTO $request): LeaveTypeResponseDTO
    {
        $this->validateManagementAccess();

        $leaveType = $this->leaveTypeRepository->findById(Uuid::fromString($id), $instituteId);

        if (!$leaveType instanceof LeaveType) {
            throw new AuthenticationException('Leave type not found.');
        }

        try {
            $this->entityManager->beginTransaction();

            if ($request->name !== null) {
                $leaveType->setName($request->name);
            }
            if ($request->code !== null && $leaveType->getCode() !== $request->code) {
                if ($this->leaveTypeRepository->codeExists($request->code, $instituteId, $leaveType->getId())) {
                    throw new \App\Common\Exception\BusinessException('Leave type code already exists for this institute.');
                }
                $leaveType->setCode($request->code);
            }
            if ($request->description !== null) {
                $leaveType->setDescription($request->description);
            }
            if ($request->enabled !== null) {
                $leaveType->setEnabled($request->enabled);
            }
            if ($request->requiresApproval !== null) {
                $leaveType->setRequiresApproval($request->requiresApproval);
            }
            if ($request->maximumDays !== null) {
                $leaveType->setMaximumDays($request->maximumDays);
            }
            if ($request->carryForward !== null) {
                $leaveType->setCarryForward($request->carryForward);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            if ($e instanceof \App\Common\Exception\BusinessException) {
                throw $e;
            }
            throw new \App\Common\Exception\BusinessException('Failed to update leave type. Please try again.', previous: $e);
        }

        return $this->mapLeaveTypeToResponse($leaveType);
    }

    public function deleteLeaveType(?Uuid $instituteId, string $id): void
    {
        $this->validateManagementAccess();

        $leaveType = $this->leaveTypeRepository->findById(Uuid::fromString($id), $instituteId);

        if (!$leaveType instanceof LeaveType) {
            throw new AuthenticationException('Leave type not found.');
        }

        try {
            $this->entityManager->beginTransaction();
            $this->entityManager->remove($leaveType);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            throw new \App\Common\Exception\BusinessException('Failed to delete leave type. Please try again.', previous: $e);
        }
    }

    // ==================== LEAVE REQUEST MANAGEMENT ====================

    public function getLeaveRequests(
        ?Uuid $instituteId,
        string $userId,
        LeaveRequestFilterRequest $filter
    ): LeaveRequestListResponse {
        $user = $this->getCurrentUser($userId);
        $filter = $this->resolveUserIdForFilter($user, $filter);

        $result = $this->leaveRequestRepository->findPaginated(
            $instituteId,
            $filter->studentId ? Uuid::fromString($filter->studentId) : null,
            $filter->leaveTypeId ? Uuid::fromString($filter->leaveTypeId) : null,
            $filter->status,
            $filter->startDate ? \DateTimeImmutable::createFromFormat('Y-m-d', $filter->startDate) : null,
            $filter->endDate ? \DateTimeImmutable::createFromFormat('Y-m-d', $filter->endDate) : null,
            $filter->page,
            $filter->limit,
            $filter->sortBy,
            $filter->sortOrder,
        );

        $items = array_map(
            fn (LeaveRequest $request) => $this->mapLeaveRequestToResponse($request),
            $result['items'],
        );

        return new LeaveRequestListResponse(
            items: $items,
            pagination: $result['pagination'],
        );
    }

    public function getLeaveRequest(?Uuid $instituteId, string $userId, string $id): LeaveRequestResponseDTO
    {
        $user = $this->getCurrentUser($userId);

        $leaveRequest = $this->leaveRequestRepository->findById(Uuid::fromString($id), $instituteId);

        if (!$leaveRequest instanceof LeaveRequest) {
            throw new AuthenticationException('Leave request not found.');
        }

        $this->validateLeaveRequestViewAccess($user, $leaveRequest);

        return $this->mapLeaveRequestToResponse($leaveRequest);
    }

    public function applyLeave(
        ?Uuid $instituteId,
        string $userId,
        LeaveRequestCreateRequestDTO $request
    ): LeaveRequestResponseDTO {
        $user = $this->getCurrentUser($userId);

        $student = $this->entityManager->getReference(User::class, Uuid::fromString($userId));

        if (!in_array(UserRole::Student->value, $user->getRoles(), true)) {
            throw new AuthenticationException('Only students can apply for leave.');
        }

        $leaveType = $this->leaveTypeRepository->find($request->leaveTypeId);
        if (!$leaveType instanceof LeaveType) {
            throw new \InvalidArgumentException('Leave type not found.');
        }

        if (!$leaveType->isEnabled()) {
            throw new \App\Common\Exception\BusinessException('This leave type is not available.');
        }

        if ($instituteId !== null && $leaveType->getInstitute()?->getId() !== null) {
            if (!$leaveType->getInstitute()->getId()->equals($instituteId)) {
                throw new \InvalidArgumentException('Leave type does not belong to your institute.');
            }
        }

        $startDate = \DateTimeImmutable::createFromFormat('Y-m-d', $request->startDate);
        if (!$startDate) {
            throw new \InvalidArgumentException('Invalid start date format. Expected YYYY-MM-DD.');
        }

        $endDate = \DateTimeImmutable::createFromFormat('Y-m-d', $request->endDate);
        if (!$endDate) {
            throw new \InvalidArgumentException('Invalid end date format. Expected YYYY-MM-DD.');
        }

        $startDate = $startDate->setTime(0, 0, 0);
        $endDate = $endDate->setTime(0, 0, 0);

        if ($endDate < $startDate) {
            throw new \App\Common\Exception\BusinessException('End date must be on or after start date.');
        }

        $enrollment = $this->studentEnrollmentRepository->findActiveEnrollmentByStudent($user->getId());
        if (!$enrollment instanceof StudentEnrollment) {
            throw new \App\Common\Exception\BusinessException('Student has no active enrollment.');
        }

        $academicYear = $enrollment->getAcademicYear();
        $academicClass = $enrollment->getAcademicClass();

        if ($instituteId !== null && !$academicYear->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Student enrollment does not belong to your institute.');
        }

        $overlap = $this->leaveRequestRepository->findOverlappingRequests(
            $user,
            $startDate,
            $endDate
        );

        if (!empty($overlap)) {
            throw new \App\Common\Exception\BusinessException(
                'Overlapping leave request already exists for this student during this period.'
            );
        }

        $section = null;
        if (count($enrollment->getEnrollmentSections()) > 0) {
            $enrollmentSection = $enrollment->getEnrollmentSections()->first();
            if ($enrollmentSection instanceof EnrollmentSection) {
                $section = $enrollmentSection->getSection();
            }
        }

        try {
            $this->entityManager->beginTransaction();

            $leaveRequest = new LeaveRequest();
            $leaveRequest->setInstitute($academicYear->getInstitute());
            $leaveRequest->setAcademicYear($academicYear);
            $leaveRequest->setStudent($student);
            $leaveRequest->setAcademicClass($academicClass);
            $leaveRequest->setSection($section);
            $leaveRequest->setLeaveType($leaveType);
            $leaveRequest->setStartDate($startDate);
            $leaveRequest->setEndDate($endDate);
            $leaveRequest->setReason($request->reason);
            $leaveRequest->setAttachment($request->attachment);
            $leaveRequest->setStatus(LeaveRequestStatus::Pending);
            $leaveRequest->setSubmittedAt(new \DateTimeImmutable());

            $totalDays = $leaveRequest->getTotalDays();

            $balance = $this->leaveBalanceRepository->findByStudentAndLeaveType(
                $user->getId(),
                $leaveType->getId(),
                $academicYear->getId(),
                $instituteId
            );

            if (!$balance instanceof LeaveBalance) {
                $balance = new LeaveBalance();
                $balance->setInstitute($academicYear->getInstitute());
                $balance->setStudent($student);
                $balance->setAcademicYear($academicYear);
                $balance->setLeaveType($leaveType);
                $balance->setAllocated($leaveType->getMaximumDays() ?? 0);
            } elseif ($leaveType->getMaximumDays() !== null && $balance->getAllocated() < $leaveType->getMaximumDays()) {
                $balance->addAllocated($leaveType->getMaximumDays() - $balance->getAllocated());
            }

            $calculated = $this->leaveRequestRepository->calculateUsedAndPendingForBalance(
                $user->getId(),
                $leaveType->getId(),
                $academicYear->getId(),
                $instituteId
            );
            $dynamicRemaining = $balance->getAllocated() - $calculated['used'] - $calculated['pending'];

            if ($leaveType->getMaximumDays() !== null && $dynamicRemaining < $totalDays) {
                $this->entityManager->persist($leaveRequest);
                $this->entityManager->flush();
                $this->entityManager->rollback();

                throw new \App\Common\Exception\BusinessException(
                    'Insufficient leave balance. Available: ' . $dynamicRemaining . ' days.'
                );
            }

            if ($leaveType->isRequiresApproval()) {
                $balance->addPending($totalDays);
            }

            $this->entityManager->persist($balance);
            $this->entityManager->persist($leaveRequest);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            if ($e instanceof \App\Common\Exception\BusinessException) {
                throw $e;
            }
            throw new \App\Common\Exception\BusinessException('Failed to submit leave request. Please try again.', previous: $e);
        }

        return $this->mapLeaveRequestToResponse($leaveRequest);
    }

    public function approveLeaveRequest(
        ?Uuid $instituteId,
        string $userId,
        string $id,
        LeaveRequestApproveRequestDTO $request
    ): void {
        $user = $this->getCurrentUser($userId);

        $leaveRequest = $this->leaveRequestRepository->findById(Uuid::fromString($id), $instituteId);

        if (!$leaveRequest instanceof LeaveRequest) {
            throw new AuthenticationException('Leave request not found.');
        }

        if (!$leaveRequest->isPending()) {
            throw new \App\Common\Exception\BusinessException(
                'Only pending leave requests can be approved.'
            );
        }

        $this->validateLeaveRequestApprovalAccess($user, $leaveRequest);

        try {
            $this->entityManager->beginTransaction();

            $leaveRequest->setStatus(LeaveRequestStatus::Approved);
            $leaveRequest->setApprovedBy($user);
            $leaveRequest->setApprovedAt(new \DateTimeImmutable());
            $leaveRequest->setApprovalRemarks($request->remarks);

            $balance = $this->leaveBalanceRepository->findByStudentAndLeaveType(
                $leaveRequest->getStudent()->getId(),
                $leaveRequest->getLeaveType()->getId(),
                $leaveRequest->getAcademicYear()->getId(),
                $instituteId
            );

            $totalDays = $leaveRequest->getTotalDays();

            if ($balance instanceof LeaveBalance) {
                if ($balance->getPending() >= $totalDays) {
                    $balance->subtractPending($totalDays);
                    $balance->addUsed($totalDays);
                } else {
                    $remainingPending = $balance->getPending();
                    $balance->setPending(0);
                    $balance->addUsed($remainingPending);
                }

                if (!$leaveRequest->getLeaveType()->isRequiresApproval()) {
                    $used = min($totalDays, $balance->getAllocated() - $balance->getUsed());
                    $balance->setUsed($balance->getUsed() + $used);
                }
            }

            $this->createAttendanceForLeave($leaveRequest, $user);

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            if ($e instanceof \App\Common\Exception\BusinessException) {
                throw $e;
            }
            throw new \App\Common\Exception\BusinessException('Failed to approve leave request. Please try again.', previous: $e);
        }
    }

    public function rejectLeaveRequest(
        ?Uuid $instituteId,
        string $userId,
        string $id,
        LeaveRequestRejectRequestDTO $request
    ): void {
        $user = $this->getCurrentUser($userId);

        $leaveRequest = $this->leaveRequestRepository->findById(Uuid::fromString($id), $instituteId);

        if (!$leaveRequest instanceof LeaveRequest) {
            throw new AuthenticationException('Leave request not found.');
        }

        if (!$leaveRequest->isPending()) {
            throw new \App\Common\Exception\BusinessException(
                'Only pending leave requests can be rejected.'
            );
        }

        $this->validateLeaveRequestApprovalAccess($user, $leaveRequest);

        try {
            $this->entityManager->beginTransaction();

            $leaveRequest->setStatus(LeaveRequestStatus::Rejected);
            $leaveRequest->setApprovedBy($user);
            $leaveRequest->setApprovedAt(new \DateTimeImmutable());
            $leaveRequest->setApprovalRemarks($request->remarks);

            $balance = $this->leaveBalanceRepository->findByStudentAndLeaveType(
                $leaveRequest->getStudent()->getId(),
                $leaveRequest->getLeaveType()->getId(),
                $leaveRequest->getAcademicYear()->getId(),
                $instituteId
            );

            $totalDays = $leaveRequest->getTotalDays();

            if ($balance instanceof LeaveBalance && $balance->getPending() > 0) {
                $balance->subtractPending(min($totalDays, $balance->getPending()));
                $this->entityManager->persist($balance);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            throw new \App\Common\Exception\BusinessException('Failed to reject leave request. Please try again.', previous: $e);
        }
    }

    public function cancelLeaveRequest(?Uuid $instituteId, string $userId, string $id): void
    {
        $user = $this->getCurrentUser($userId);

        $leaveRequest = $this->leaveRequestRepository->findById(Uuid::fromString($id), $instituteId);

        if (!$leaveRequest instanceof LeaveRequest) {
            throw new AuthenticationException('Leave request not found.');
        }

        $this->validateLeaveRequestCancelAccess($user, $leaveRequest);

        try {
            $this->entityManager->beginTransaction();

            if ($leaveRequest->isApproved()) {
                $now = new \DateTimeImmutable();
                if ($leaveRequest->getStartDate() <= $now) {
                    throw new \App\Common\Exception\BusinessException(
                        'Cannot cancel an approved leave that has already started or ended.'
                    );
                }

                $balance = $this->leaveBalanceRepository->findByStudentAndLeaveType(
                    $leaveRequest->getStudent()->getId(),
                    $leaveRequest->getLeaveType()->getId(),
                    $leaveRequest->getAcademicYear()->getId(),
                    $instituteId
                );

                $totalDays = $leaveRequest->getTotalDays();

                if ($balance instanceof LeaveBalance) {
                    $balance->subtractUsed(min($totalDays, $balance->getUsed()));
                    $this->entityManager->persist($balance);
                }

                $this->removeAttendanceForLeave($leaveRequest);
            }

            if ($leaveRequest->isPending()) {
                $balance = $this->leaveBalanceRepository->findByStudentAndLeaveType(
                    $leaveRequest->getStudent()->getId(),
                    $leaveRequest->getLeaveType()->getId(),
                    $leaveRequest->getAcademicYear()->getId(),
                    $instituteId
                );

                $totalDays = $leaveRequest->getTotalDays();

                if ($balance instanceof LeaveBalance && $balance->getPending() > 0) {
                    $balance->subtractPending(min($totalDays, $balance->getPending()));
                    $this->entityManager->persist($balance);
                }
            }

            $leaveRequest->setStatus(LeaveRequestStatus::Cancelled);
            $leaveRequest->setCancelledAt(new \DateTimeImmutable());

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            if ($e instanceof \App\Common\Exception\BusinessException) {
                throw $e;
            }
            throw new \App\Common\Exception\BusinessException('Failed to cancel leave request. Please try again.', previous: $e);
        }
    }

    // ==================== LEAVE BALANCE ====================

    public function getLeaveBalances(
        ?Uuid $instituteId,
        string $userId,
        ?string $studentId = null,
        ?string $academicYearId = null
    ): LeaveBalanceListResponse {
        $user = $this->getCurrentUser($userId);

        $studentIdUuid = null;

        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            if ($studentId !== null && !Uuid::fromString($studentId)->equals($user->getId())) {
                throw new AuthenticationException('Students can only view their own leave balances.');
            }
            $studentIdUuid = $user->getId();
        } elseif ($studentId !== null) {
            $studentUser = $this->entityManager->getRepository(User::class)->find($studentId);
            if (!$studentUser instanceof User) {
                throw new AuthenticationException('Student not found.');
            }
            $this->validateStudentViewAccess($user, $studentUser, $instituteId);
            $studentIdUuid = Uuid::fromString($studentId);
        }

        $result = $this->leaveBalanceRepository->findPaginated(
            $instituteId,
            $studentIdUuid,
            $academicYearId ? Uuid::fromString($academicYearId) : null,
            null,
            1,
            100,
            'createdAt',
            'DESC',
        );

        if (empty($result['items']) && $studentIdUuid !== null) {
            $effectiveAcademicYearId = $academicYearId !== null
                ? Uuid::fromString($academicYearId)
                : $this->findCurrentAcademicYearForStudent($studentIdUuid, $instituteId);

            if ($effectiveAcademicYearId !== null) {
                $this->ensureLeaveBalancesInitialized($instituteId, $studentIdUuid, $effectiveAcademicYearId);
                $result = $this->leaveBalanceRepository->findPaginated(
                    $instituteId,
                    $studentIdUuid,
                    $effectiveAcademicYearId,
                    null,
                    1,
                    100,
                    'createdAt',
                    'DESC',
                );
            }
        }

        $items = array_map(
            fn (LeaveBalance $balance) => $this->mapLeaveBalanceToResponse($balance),
            $result['items'],
        );

        return new LeaveBalanceListResponse(
            items: $items,
            pagination: $result['pagination'],
        );
    }

    private function ensureLeaveBalancesInitialized(?Uuid $instituteId, Uuid $studentId, Uuid $academicYearId): void
    {
        $academicYear = $this->entityManager->getRepository(AcademicYear::class)->find($academicYearId);
        if (!$academicYear instanceof AcademicYear) {
            return;
        }

        if ($instituteId !== null && !$academicYear->getInstitute()->getId()->equals($instituteId)) {
            return;
        }

        $leaveTypes = $this->leaveTypeRepository->findEnabledByInstitute($instituteId);

        try {
            $this->entityManager->beginTransaction();

            foreach ($leaveTypes as $leaveType) {
                $existing = $this->leaveBalanceRepository->findByStudentAndLeaveType(
                    $studentId,
                    $leaveType->getId(),
                    $academicYearId,
                    $instituteId
                );

                if ($existing instanceof LeaveBalance) {
                    continue;
                }

                $balance = new LeaveBalance();
                $balance->setInstitute($academicYear->getInstitute());
                $balance->setStudent($this->entityManager->getReference(User::class, $studentId));
                $balance->setAcademicYear($academicYear);
                $balance->setLeaveType($leaveType);
                $balance->setAllocated($leaveType->getMaximumDays() ?? 0);

                $this->entityManager->persist($balance);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
        }
    }

    private function findCurrentAcademicYearForStudent(Uuid $studentId, ?Uuid $instituteId): ?Uuid
    {
        $enrollment = $this->studentEnrollmentRepository->findCurrentByStudent($studentId);
        if (!$enrollment instanceof StudentEnrollment) {
            return null;
        }

        $academicYear = $enrollment->getAcademicYear();
        if ($instituteId !== null && !$academicYear->getInstitute()->getId()->equals($instituteId)) {
            return null;
        }

        return $academicYear->getId();
    }

    public function initializeLeaveBalances(
        ?Uuid $instituteId,
        string $studentId,
        string $academicYearId
    ): void {
        $user = $this->getCurrentUser($studentId);

        $studentUser = $this->entityManager->getRepository(User::class)->find($studentId);
        if (!$studentUser instanceof User) {
            throw new AuthenticationException('Student not found.');
        }

        $this->validateStudentViewAccess($user, $studentUser, $instituteId);

        $academicYear = $this->entityManager->getRepository(AcademicYear::class)->find($academicYearId);
        if (!$academicYear instanceof AcademicYear) {
            throw new \InvalidArgumentException('Academic year not found.');
        }

        if ($instituteId !== null && !$academicYear->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Academic year does not belong to your institute.');
        }

        $this->ensureLeaveBalancesInitialized($instituteId, $studentUser->getId(), Uuid::fromString($academicYearId));
    }

    // ==================== INTERNAL HELPERS ====================

    private function getCurrentUser(string $userId): User
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return $user;
    }

    private function validateManagementAccess(): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            throw new AuthenticationException('Students are not authorized to manage leave types.');
        }
    }

    private function resolveUserIdForFilter(User $user, LeaveRequestFilterRequest $filter): LeaveRequestFilterRequest
    {
        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            return new LeaveRequestFilterRequest(
                studentId: (string) $user->getId(),
                leaveTypeId: $filter->leaveTypeId,
                status: $filter->status,
                startDate: $filter->startDate,
                endDate: $filter->endDate,
                page: $filter->page,
                limit: $filter->limit,
                sortBy: $filter->sortBy,
                sortOrder: $filter->sortOrder,
            );
        }

        return $filter;
    }

    private function validateLeaveRequestViewAccess(User $user, LeaveRequest $leaveRequest): void
    {
        if (in_array(UserRole::SuperAdmin->value, $user->getRoles(), true)) {
            return;
        }

        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            if (!$leaveRequest->getStudent()->getId()->equals($user->getId())) {
                throw new AuthenticationException('You can only view your own leave requests.');
            }
            return;
        }

        if (in_array(UserRole::Teacher->value, $user->getRoles(), true)) {
            $this->validateTeacherViewAccess($user, $leaveRequest);
            return;
        }
    }

    private function validateTeacherViewAccess(User $user, LeaveRequest $leaveRequest): void
    {
        $student = $leaveRequest->getStudent();
        $academicYear = $leaveRequest->getAcademicYear();
        $academicClass = $leaveRequest->getAcademicClass();
        $section = $leaveRequest->getSection();

        $assignment = $this->teacherAssignmentRepository->findActiveByTeacherAndClassAndSection(
            $user->getId(),
            $academicYear->getId(),
            $academicClass->getId(),
            $section ? $section->getId() : null
        );

        if (!$assignment) {
            throw new AuthenticationException('You are not authorized to view this leave request.');
        }
    }

    private function validateLeaveRequestApprovalAccess(User $user, LeaveRequest $leaveRequest): void
    {
        if (in_array(UserRole::SuperAdmin->value, $user->getRoles(), true) || in_array(UserRole::Institute->value, $user->getRoles(), true)) {
            return;
        }

        if (in_array(UserRole::Teacher->value, $user->getRoles(), true)) {
            $this->validateTeacherViewAccess($user, $leaveRequest);
            return;
        }

        throw new AuthenticationException('You are not authorized to approve leave requests.');
    }

    private function validateLeaveRequestCancelAccess(User $user, LeaveRequest $leaveRequest): void
    {
        if (in_array(UserRole::SuperAdmin->value, $user->getRoles(), true) || in_array(UserRole::Institute->value, $user->getRoles(), true)) {
            return;
        }

        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            if (!$leaveRequest->getStudent()->getId()->equals($user->getId())) {
                throw new AuthenticationException('You can only cancel your own leave requests.');
            }
            return;
        }

        if (in_array(UserRole::Teacher->value, $user->getRoles(), true)) {
            $this->validateTeacherViewAccess($user, $leaveRequest);
            return;
        }

        throw new AuthenticationException('You are not authorized to cancel leave requests.');
    }

    private function validateStudentViewAccess(User $user, User $studentUser, ?Uuid $instituteId): void
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
    }

    private function createAttendanceForLeave(LeaveRequest $leaveRequest, User $markedBy): void
    {
        $student = $leaveRequest->getStudent();
        $academicYear = $leaveRequest->getAcademicYear();
        $academicClass = $leaveRequest->getAcademicClass();
        $section = $leaveRequest->getSection();

        $start = $leaveRequest->getStartDate();
        $end = $leaveRequest->getEndDate();

        $current = $start;
        while ($current <= $end) {
            if ($current->format('N') >= 6) {
                $current = $current->modify('+1 day');
                continue;
            }

            if ($this->holidayRepository->isHoliday($current, $academicYear->getInstitute()->getId(), $academicYear->getId())) {
                $current = $current->modify('+1 day');
                continue;
            }

            $existing = $this->attendanceRecordRepository->findByStudentAndDate(
                $student->getId(),
                $academicClass->getId(),
                $current,
                $section ? $section->getId() : null
            );

            if (!$existing instanceof AttendanceRecord) {
                $record = new AttendanceRecord();
                $record->setInstitute($academicYear->getInstitute());
                $record->setAcademicYear($academicYear);
                $record->setStudent($student);
                $record->setAcademicClass($academicClass);
                $record->setSection($section);
                $record->setAttendanceDate($current);
                $record->setStatus(AttendanceStatus::OnLeave);
                $record->setSource(AttendanceRecord::SOURCE_LEAVE_APPROVAL);
                $record->setMarkedBy($markedBy);
                $record->setMarkedAt(new \DateTimeImmutable());
                $record->setLeaveRequest($leaveRequest);
                $this->entityManager->persist($record);
            } else {
                if ($existing->getSource() === AttendanceRecord::SOURCE_LEAVE_APPROVAL) {
                    $existing->setStatus(AttendanceStatus::OnLeave);
                }
            }

            $current = $current->modify('+1 day');
        }
    }

    private function removeAttendanceForLeave(LeaveRequest $leaveRequest): void
    {
        $records = $this->attendanceRecordRepository->createQueryBuilder('ar')
            ->where('ar.leaveRequest = :leaveRequest')
            ->andWhere('ar.source = :source')
            ->setParameter('leaveRequest', $leaveRequest)
            ->setParameter('source', AttendanceRecord::SOURCE_LEAVE_APPROVAL)
            ->getQuery()
            ->getResult();

        foreach ($records as $record) {
            $this->entityManager->remove($record);
        }
    }

    private function mapLeaveTypeToResponse(LeaveType $leaveType): LeaveTypeResponseDTO
    {
        return new LeaveTypeResponseDTO(
            id: (string) $leaveType->getId(),
            institute: $leaveType->getInstitute() ? new InstituteReferenceDTO(
                id: (string) $leaveType->getInstitute()->getId(),
                name: $leaveType->getInstitute()->getName(),
            ) : null,
            name: $leaveType->getName(),
            code: $leaveType->getCode(),
            description: $leaveType->getDescription(),
            enabled: $leaveType->isEnabled(),
            requiresApproval: $leaveType->isRequiresApproval(),
            maximumDays: $leaveType->getMaximumDays(),
            carryForward: $leaveType->isCarryForward(),
            createdAt: $leaveType->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $leaveType->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }

    private function mapLeaveRequestToResponse(LeaveRequest $leaveRequest): LeaveRequestResponseDTO
    {
        $student = $leaveRequest->getStudent();
        $academicYear = $leaveRequest->getAcademicYear();
        $academicClass = $leaveRequest->getAcademicClass();
        $section = $leaveRequest->getSection();
        $leaveType = $leaveRequest->getLeaveType();
        $institute = $leaveRequest->getInstitute();
        $approvedBy = $leaveRequest->getApprovedBy();

        return new LeaveRequestResponseDTO(
            id: (string) $leaveRequest->getId(),
            institute: $institute ? new InstituteReferenceDTO(
                id: (string) $institute->getId(),
                name: $institute->getName(),
            ) : null,
            student: new StudentReferenceDTO(
                id: (string) $student->getId(),
                name: trim(($student->getFirstName() ?? '') . ' ' . ($student->getLastName() ?? '')),
                email: $student->getEmail(),
                rollNumber: null,
            ),
            academicYear: new AcademicYearReferenceDTO(
                id: (string) $academicYear->getId(),
                name: $academicYear->getName(),
            ),
            academicClass: $academicClass ? new AcademicClassReferenceDTO(
                id: (string) $academicClass->getId(),
                name: $academicClass->getName(),
                code: $academicClass->getCode(),
                startDate: $academicClass->getStartDate()?->format(DateTimeInterface::ATOM),
                endDate: $academicClass->getEndDate()?->format(DateTimeInterface::ATOM),
            ) : null,
            section: $section ? new \App\DTO\AttendanceModule\SectionReferenceDTO(
                id: (string) $section->getId(),
                name: $section->getName(),
                code: $section->getCode(),
            ) : null,
            leaveType: new LeaveTypeSummaryDTO(
                id: (string) $leaveType->getId(),
                name: $leaveType->getName(),
                code: $leaveType->getCode(),
            ),
            startDate: $leaveRequest->getStartDate()->format('Y-m-d'),
            endDate: $leaveRequest->getEndDate()->format('Y-m-d'),
            reason: $leaveRequest->getReason(),
            attachment: $leaveRequest->getAttachment(),
            status: $leaveRequest->getStatusValue(),
            approvedBy: $approvedBy ? new UserReferenceDTO(
                id: (string) $approvedBy->getId(),
                name: trim(($approvedBy->getFirstName() ?? '') . ' ' . ($approvedBy->getLastName() ?? '')),
                email: $approvedBy->getEmail(),
            ) : null,
            approvalRemarks: $leaveRequest->getApprovalRemarks(),
            submittedAt: $leaveRequest->getSubmittedAt()->format(DateTimeInterface::ATOM),
            approvedAt: $leaveRequest->getApprovedAt()?->format(DateTimeInterface::ATOM),
            cancelledAt: $leaveRequest->getCancelledAt()?->format(DateTimeInterface::ATOM),
            cancellationReason: $leaveRequest->getCancellationReason(),
            totalDays: $leaveRequest->getTotalDays(),
            createdAt: $leaveRequest->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $leaveRequest->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }

    private function mapLeaveBalanceToResponse(LeaveBalance $balance): LeaveBalanceResponseDTO
    {
        $student = $balance->getStudent();
        $academicYear = $balance->getAcademicYear();
        $leaveType = $balance->getLeaveType();
        $institute = $balance->getInstitute();

        $rollNumber = null;
        $enrollment = $this->studentEnrollmentRepository->createQueryBuilder('se')
            ->where('se.student = :student')
            ->andWhere('se.academicYear = :academicYear')
            ->setParameter('student', $student->getId())
            ->setParameter('academicYear', $academicYear->getId())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($enrollment instanceof StudentEnrollment) {
            $rollNumber = $enrollment->getRollNumber();
        }

        $calculated = $this->leaveRequestRepository->calculateUsedAndPendingForBalance(
            $student->getId(),
            $leaveType->getId(),
            $academicYear->getId(),
            $institute ? $institute->getId() : null
        );

        $allocated = $balance->getAllocated();
        $used = $calculated['used'];
        $pending = $calculated['pending'];

        return new LeaveBalanceResponseDTO(
            id: (string) $balance->getId(),
            institute: $institute ? new InstituteReferenceDTO(
                id: (string) $institute->getId(),
                name: $institute->getName(),
            ) : null,
            studentId: (string) $student->getId(),
            studentName: trim(($student->getFirstName() ?? '') . ' ' . ($student->getLastName() ?? '')),
            academicYear: new AcademicYearReferenceDTO(
                id: (string) $academicYear->getId(),
                name: $academicYear->getName(),
            ),
            leaveType: new LeaveTypeSummaryDTO(
                id: (string) $leaveType->getId(),
                name: $leaveType->getName(),
                code: $leaveType->getCode(),
            ),
            allocated: $allocated,
            used: $used,
            pending: $pending,
            remaining: max(0, $allocated - $used - $pending),
            createdAt: $balance->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $balance->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }
}
