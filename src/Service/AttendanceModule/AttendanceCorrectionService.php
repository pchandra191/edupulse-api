<?php
declare(strict_types=1);

namespace App\Service\AttendanceModule;

use App\DTO\AttendanceModule\AcademicClassReferenceDTO;
use App\DTO\AttendanceModule\AcademicYearReferenceDTO;
use App\DTO\AttendanceModule\AttendanceCorrectionApproveRequestDTO;
use App\DTO\AttendanceModule\AttendanceCorrectionCreateRequestDTO;
use App\DTO\AttendanceModule\AttendanceCorrectionFilterRequest;
use App\DTO\AttendanceModule\AttendanceCorrectionRejectRequestDTO;
use App\DTO\AttendanceModule\AttendanceCorrectionResponseDTO;
use App\DTO\AttendanceModule\InstituteReferenceDTO;
use App\DTO\AttendanceModule\SectionReferenceDTO;
use App\DTO\AttendanceModule\StudentReferenceDTO;
use App\DTO\AttendanceModule\UserReferenceDTO;
use App\DTO\Response\AttendanceCorrectionListResponse;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\AttendanceCorrection;
use App\Entity\AttendanceRecord;
use App\Entity\EnrollmentSection;
use App\Entity\Institute;
use App\Entity\StudentEnrollment;
use App\Entity\User;
use App\Enum\AttendanceCorrectionStatus;
use App\Enum\AttendanceStatus;
use App\Enum\UserRole;
use App\Exception\AuthenticationException;
use App\Repository\AttendanceCorrectionRepository;
use App\Repository\AttendanceRecordRepository;
use App\Repository\StudentEnrollmentRepository;
use App\Repository\TeacherAssignmentRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

readonly class AttendanceCorrectionService
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private AttendanceCorrectionRepository $correctionRepository,
        private AttendanceRecordRepository $attendanceRecordRepository,
        private StudentEnrollmentRepository $studentEnrollmentRepository,
        private TeacherAssignmentRepository $teacherAssignmentRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function getCorrections(
        ?Uuid $instituteId,
        AttendanceCorrectionFilterRequest $filter
    ): AttendanceCorrectionListResponse {
        $user = $this->getCurrentUser();

        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            $filter = new AttendanceCorrectionFilterRequest(
                studentId: (string) $user->getId(),
                attendanceRecordId: $filter->attendanceRecordId,
                status: $filter->status,
                page: $filter->page,
                limit: $filter->limit,
                sortBy: $filter->sortBy,
                sortOrder: $filter->sortOrder,
            );
        }

        $result = $this->correctionRepository->findPaginated(
            $instituteId,
            $filter->studentId ? Uuid::fromString($filter->studentId) : null,
            $filter->attendanceRecordId ? Uuid::fromString($filter->attendanceRecordId) : null,
            $filter->status,
            $filter->page,
            $filter->limit,
            $filter->sortBy,
            $filter->sortOrder,
        );

        $items = array_map(
            fn (AttendanceCorrection $correction) => $this->mapToResponse($correction),
            $result['items'],
        );

        return new AttendanceCorrectionListResponse(
            items: $items,
            pagination: $result['pagination'],
        );
    }

    public function getCorrection(?Uuid $instituteId, string $id): AttendanceCorrectionResponseDTO
    {
        $user = $this->getCurrentUser();

        $correction = $this->correctionRepository->findById(Uuid::fromString($id), $instituteId);

        if (!$correction instanceof AttendanceCorrection) {
            throw new AuthenticationException('Attendance correction not found.');
        }

        $student = $correction->getStudent();

        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            if (!$student->getId()->equals($user->getId())) {
                throw new AuthenticationException('You can only view your own attendance corrections.');
            }
        } else {
            $this->validateStudentViewAccess($user, $student, $instituteId);
        }

        return $this->mapToResponse($correction);
    }

    public function requestCorrection(
        ?Uuid $instituteId,
        string $userId,
        AttendanceCorrectionCreateRequestDTO $request
    ): AttendanceCorrectionResponseDTO {
        $user = $this->getCurrentUser();

        $attendanceRecord = $this->attendanceRecordRepository->findById(
            Uuid::fromString($request->attendanceRecordId),
            $instituteId
        );

        if (!$attendanceRecord instanceof AttendanceRecord) {
            throw new AuthenticationException('Attendance record not found.');
        }

        $student = $attendanceRecord->getStudent();

        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            if (!$student->getId()->equals($user->getId())) {
                throw new AuthenticationException('You can only request corrections for your own attendance records.');
            }
        } else {
            $this->validateStudentViewAccess($user, $student, $instituteId);
        }

        try {
            $this->entityManager->beginTransaction();

            $correction = new AttendanceCorrection();
            $correction->setInstitute($attendanceRecord->getInstitute());
            $correction->setAcademicYear($attendanceRecord->getAcademicYear());
            $correction->setAttendanceRecord($attendanceRecord);
            $correction->setStudent($student);
            $correction->setAcademicClass($attendanceRecord->getAcademicClass());
            $correction->setSection($attendanceRecord->getSection());
            $correction->setOriginalStatus($attendanceRecord->getStatusValue());
            $correction->setCorrectedStatus($request->correctedStatus);
            $correction->setReason($request->reason);
            $correction->setRequestedBy($user);
            $correction->setStatus(AttendanceCorrectionStatus::Pending);
            $correction->setRequestedAt(new \DateTimeImmutable());

            $this->entityManager->persist($correction);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to request attendance correction.', [
                'record_id' => $request->attendanceRecordId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to request attendance correction. Please try again.', previous: $e);
        }

        return $this->mapToResponse($correction);
    }

    public function approveCorrection(
        ?Uuid $instituteId,
        string $userId,
        string $id,
        AttendanceCorrectionApproveRequestDTO $request
    ): void {
        $user = $this->getCurrentUser();

        $correction = $this->correctionRepository->findById(Uuid::fromString($id), $instituteId);

        if (!$correction instanceof AttendanceCorrection) {
            throw new AuthenticationException('Attendance correction not found.');
        }

        if (!$correction->isPending()) {
            throw new \App\Common\Exception\BusinessException(
                'Only pending corrections can be approved.'
            );
        }

        $this->validateCorrectionApprovalAccess($user, $correction, $instituteId);

        try {
            $this->entityManager->beginTransaction();

            $correction->setStatus(AttendanceCorrectionStatus::Approved);
            $correction->setApprovedBy($user);
            $correction->setApprovedAt(new \DateTimeImmutable());
            $correction->setRemarks($request->remarks);

            $attendanceRecord = $correction->getAttendanceRecord();
            $attendanceRecord->setStatus(AttendanceStatus::from($correction->getCorrectedStatus()));
            $attendanceRecord->setSource(AttendanceRecord::SOURCE_CORRECTION);
            $attendanceRecord->setMarkedBy($user);
            $attendanceRecord->setMarkedAt(new \DateTimeImmutable());

            if ($request->remarks !== null) {
                $attendanceRecord->setRemarks($request->remarks);
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

            $this->logger->error('Failed to approve attendance correction.', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to approve attendance correction. Please try again.', previous: $e);
        }
    }

    public function rejectCorrection(
        ?Uuid $instituteId,
        string $userId,
        string $id,
        AttendanceCorrectionRejectRequestDTO $request
    ): void {
        $user = $this->getCurrentUser();

        $correction = $this->correctionRepository->findById(Uuid::fromString($id), $instituteId);

        if (!$correction instanceof AttendanceCorrection) {
            throw new AuthenticationException('Attendance correction not found.');
        }

        if (!$correction->isPending()) {
            throw new \App\Common\Exception\BusinessException(
                'Only pending corrections can be rejected.'
            );
        }

        $this->validateCorrectionApprovalAccess($user, $correction, $instituteId);

        try {
            $this->entityManager->beginTransaction();

            $correction->setStatus(AttendanceCorrectionStatus::Rejected);
            $correction->setApprovedBy($user);
            $correction->setApprovedAt(new \DateTimeImmutable());
            $correction->setRemarks($request->remarks);

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to reject attendance correction.', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to reject attendance correction. Please try again.', previous: $e);
        }
    }

    private function getCurrentUser(): User
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return $user;
    }

    private function validateStudentViewAccess(User $user, User $studentUser, ?Uuid $instituteId): void
    {
        if (in_array(UserRole::SuperAdmin->value, $user->getRoles(), true)) {
            return;
        }

        if (in_array(UserRole::Student->value, $user->getRoles(), true)) {
            if (!$studentUser->getId()->equals($user->getId())) {
                throw new AuthenticationException('You can only view your own data.');
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

    private function validateCorrectionApprovalAccess(User $user, AttendanceCorrection $correction, ?Uuid $instituteId): void
    {
        if (in_array(UserRole::SuperAdmin->value, $user->getRoles(), true) || in_array(UserRole::Institute->value, $user->getRoles(), true)) {
            return;
        }

        if (in_array(UserRole::Teacher->value, $user->getRoles(), true)) {
            $student = $correction->getStudent();
            $academicYear = $correction->getAcademicYear();
            $academicClass = $correction->getAcademicClass();
            $section = $correction->getSection();

            $hasAssignment = $this->teacherAssignmentRepository->findActiveByTeacherAndClassAndSection(
                $user->getId(),
                $academicYear->getId(),
                $academicClass->getId(),
                $section ? $section->getId() : null
            );

            if (!$hasAssignment) {
                throw new AuthenticationException('You are not authorized to approve this correction.');
            }

            return;
        }

        throw new AuthenticationException('You are not authorized to approve corrections.');
    }

    private function mapToResponse(AttendanceCorrection $correction): AttendanceCorrectionResponseDTO
    {
        $student = $correction->getStudent();
        $academicYear = $correction->getAcademicYear();
        $academicClass = $correction->getAcademicClass();
        $section = $correction->getSection();
        $institute = $correction->getInstitute();
        $requestedBy = $correction->getRequestedBy();
        $approvedBy = $correction->getApprovedBy();

        $rollNumber = null;
        if ($student && $academicYear) {
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
        }

        return new AttendanceCorrectionResponseDTO(
            id: (string) $correction->getId(),
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
            academicClass: $academicClass ? new AcademicClassReferenceDTO(
                id: (string) $academicClass->getId(),
                name: $academicClass->getName(),
                code: $academicClass->getCode(),
                startDate: $academicClass->getStartDate()?->format(DateTimeInterface::ATOM),
                endDate: $academicClass->getEndDate()?->format(DateTimeInterface::ATOM),
            ) : null,
            section: $section ? new SectionReferenceDTO(
                id: (string) $section->getId(),
                name: $section->getName(),
                code: $section->getCode(),
            ) : null,
            attendanceRecordId: (string) $correction->getAttendanceRecord()->getId(),
            originalStatus: $correction->getOriginalStatus(),
            correctedStatus: $correction->getCorrectedStatus(),
            reason: $correction->getReason(),
            requestedBy: $requestedBy ? new UserReferenceDTO(
                id: (string) $requestedBy->getId(),
                name: trim(($requestedBy->getFirstName() ?? '') . ' ' . ($requestedBy->getLastName() ?? '')),
                email: $requestedBy->getEmail(),
            ) : null,
            approvedBy: $approvedBy ? new UserReferenceDTO(
                id: (string) $approvedBy->getId(),
                name: trim(($approvedBy->getFirstName() ?? '') . ' ' . ($approvedBy->getLastName() ?? '')),
                email: $approvedBy->getEmail(),
            ) : null,
            status: $correction->getStatusValue(),
            remarks: $correction->getRemarks(),
            requestedAt: $correction->getRequestedAt()->format(DateTimeInterface::ATOM),
            approvedAt: $correction->getApprovedAt()?->format(DateTimeInterface::ATOM),
            createdAt: $correction->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $correction->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }
}
