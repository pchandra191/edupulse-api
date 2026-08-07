<?php
declare(strict_types=1);

namespace App\Service\TeacherAssignmentModule;

use App\DTO\TeacherAssignmentModule\AcademicClassResponse;
use App\DTO\TeacherAssignmentModule\AcademicYearResponse;
use App\DTO\TeacherAssignmentModule\CreateTeacherAssignmentRequestDTO;
use App\DTO\TeacherAssignmentModule\SectionResponse;
use App\DTO\TeacherAssignmentModule\SubjectResponse;
use App\DTO\TeacherAssignmentModule\TeacherAssignmentFilterRequest;
use App\DTO\TeacherAssignmentModule\TeacherAssignmentResponseDTO;
use App\DTO\TeacherAssignmentModule\TeacherResponse;
use App\DTO\TeacherAssignmentModule\UpdateTeacherAssignmentRequestDTO;
use App\DTO\Response\TeacherAssignmentListResponse;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\Institute;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\TeacherAssignment;
use App\Entity\User;
use App\Enum\TeacherAssignmentStatus;
use App\Enum\UserRole;
use App\Exception\AuthenticationException;
use App\Repository\InstituteRepository;
use App\Repository\TeacherAssignmentRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

readonly class TeacherAssignmentService
{
    public function __construct(
        private Security $security,
        private TeacherAssignmentRepository $teacherAssignmentRepository,
        private InstituteRepository $instituteRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function getTeacherAssignments(?Uuid $instituteId, TeacherAssignmentFilterRequest $filter): TeacherAssignmentListResponse
    {
        $result = $this->teacherAssignmentRepository->findPaginated(
            $instituteId,
            $filter->teacherId ? Uuid::fromString($filter->teacherId) : null,
            $filter->academicYearId ? Uuid::fromString($filter->academicYearId) : null,
            $filter->classId ? Uuid::fromString($filter->classId) : null,
            $filter->sectionId ? Uuid::fromString($filter->sectionId) : null,
            $filter->subjectId ? Uuid::fromString($filter->subjectId) : null,
            $filter->search,
            $filter->status,
            $filter->page,
            $filter->limit,
            $filter->sortBy,
            $filter->sortOrder,
        );

        $items = array_map(
            fn (TeacherAssignment $assignment) => $this->mapToResponse($assignment),
            $result['items'],
        );

        return new TeacherAssignmentListResponse(
            items: $items,
            pagination: $result['pagination'],
        );
    }

    public function getTeacherAssignment(Uuid $id, ?Uuid $instituteId): TeacherAssignmentResponseDTO
    {
        $assignment = $this->teacherAssignmentRepository->findById($id, $instituteId);

        if (!$assignment instanceof TeacherAssignment) {
            throw new AuthenticationException('Teacher assignment not found.');
        }

        return $this->mapToResponse($assignment);
    }

    public function createTeacherAssignment(?Uuid $instituteId, CreateTeacherAssignmentRequestDTO $request): TeacherAssignmentResponseDTO
    {
        $teacher = $this->entityManager->getRepository(User::class)->find($request->teacherId);

        if (!$teacher instanceof User) {
            throw new \InvalidArgumentException('Teacher not found.');
        }

        $this->validateTeacher($teacher, $instituteId);

        $academicYear = $this->entityManager->getRepository(AcademicYear::class)->find($request->academicYearId);

        if (!$academicYear instanceof AcademicYear) {
            throw new \InvalidArgumentException('Academic year not found.');
        }

        $this->validateAcademicYear($academicYear, $instituteId);

        $academicClass = $this->entityManager->getRepository(AcademicClass::class)->find($request->academicClassId);

        if (!$academicClass instanceof AcademicClass) {
            throw new \InvalidArgumentException('Academic class not found.');
        }

        if ($instituteId !== null && !$academicClass->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Academic class does not belong to the authenticated institute.');
        }

        $section = null;

        if ($request->sectionId !== null) {
            $section = $this->entityManager->getRepository(Section::class)->find($request->sectionId);

            if (!$section instanceof Section) {
                throw new \InvalidArgumentException('Section not found.');
            }

            if (!$section->getAcademicClass()->getId()->equals($academicClass->getId())) {
                throw new \InvalidArgumentException('Section does not belong to the selected class.');
            }
        }

        $subject = null;

        if ($request->subjectId !== null) {
            $subject = $this->entityManager->getRepository(Subject::class)->find($request->subjectId);

            if (!$subject instanceof Subject) {
                throw new \InvalidArgumentException('Subject not found.');
            }

            if (!$subject->getInstitute()->getId()->equals($academicClass->getInstitute()->getId())) {
                throw new \InvalidArgumentException('Subject does not belong to the authenticated institute.');
            }
        }

        $duplicate = $this->teacherAssignmentRepository->findActiveDuplicate(
            $teacher,
            $academicYear,
            $academicClass,
            $section,
            $subject
        );

        if ($duplicate instanceof TeacherAssignment) {
            throw new \App\Common\Exception\BusinessException('An active assignment already exists for this combination.');
        }

        try {
            $this->entityManager->beginTransaction();

            $assignment = new TeacherAssignment();
            $assignment->setTeacher($teacher);
            $assignment->setAcademicYear($academicYear);
            $assignment->setAcademicClass($academicClass);
            $assignment->setSection($section);
            $assignment->setSubject($subject);
            $assignment->setAssignmentType($request->assignmentType);
            $assignment->setRemarks($request->remarks);
            $assignment->setStatus(TeacherAssignmentStatus::from($request->status));

            $this->entityManager->persist($assignment);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($assignment);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to create teacher assignment.', [
                'teacher_id' => $request->teacherId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to create teacher assignment. Please try again.', previous: $e);
        }
    }

    public function updateTeacherAssignment(Uuid $id, ?Uuid $instituteId, UpdateTeacherAssignmentRequestDTO $request): TeacherAssignmentResponseDTO
    {
        $assignment = $this->teacherAssignmentRepository->findById($id, $instituteId);

        if (!$assignment instanceof TeacherAssignment) {
            throw new AuthenticationException('Teacher assignment not found.');
        }

        try {
            $this->entityManager->beginTransaction();
            $this->updateFields($assignment, $request, $instituteId);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($assignment);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to update teacher assignment.', [
                'assignment_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to update teacher assignment. Please try again.', previous: $e);
        }
    }

    public function deleteTeacherAssignment(Uuid $id, ?Uuid $instituteId): void
    {
        $assignment = $this->teacherAssignmentRepository->findById($id, $instituteId);

        if (!$assignment instanceof TeacherAssignment) {
            throw new AuthenticationException('Teacher assignment not found.');
        }

        $assignment->setStatus(TeacherAssignmentStatus::Archived);

        $this->entityManager->flush();
    }

    private function updateFields(TeacherAssignment $assignment, UpdateTeacherAssignmentRequestDTO $request, ?Uuid $instituteId): void
    {
        if ($request->teacherId !== null && $request->teacherId !== (string) $assignment->getTeacher()->getId()) {
            $teacher = $this->entityManager->getRepository(User::class)->find($request->teacherId);

            if (!$teacher instanceof User) {
                throw new \InvalidArgumentException('Teacher not found.');
            }

            $this->validateTeacher($teacher, $instituteId);

            $assignment->setTeacher($teacher);
        }

        if ($request->academicYearId !== null && $request->academicYearId !== (string) $assignment->getAcademicYear()->getId()) {
            $academicYear = $this->entityManager->getRepository(AcademicYear::class)->find($request->academicYearId);

            if (!$academicYear instanceof AcademicYear) {
                throw new \InvalidArgumentException('Academic year not found.');
            }

            $this->validateAcademicYear($academicYear, $instituteId);

            $assignment->setAcademicYear($academicYear);
        }

        if ($request->academicClassId !== null && $request->academicClassId !== (string) $assignment->getAcademicClass()->getId()) {
            $academicClass = $this->entityManager->getRepository(AcademicClass::class)->find($request->academicClassId);

            if (!$academicClass instanceof AcademicClass) {
                throw new \InvalidArgumentException('Academic class not found.');
            }

            if ($instituteId !== null && !$academicClass->getInstitute()->getId()->equals($instituteId)) {
                throw new \InvalidArgumentException('Academic class does not belong to the authenticated institute.');
            }

            $assignment->setAcademicClass($academicClass);
            $assignment->setSection(null);
            $assignment->setSubject(null);
        }

        if ($request->sectionId !== null) {
            if ($request->sectionId === '') {
                $assignment->setSection(null);
            } else {
                $section = $this->entityManager->getRepository(Section::class)->find($request->sectionId);

                if (!$section instanceof Section) {
                    throw new \InvalidArgumentException('Section not found.');
                }

                if (!$section->getAcademicClass()->getId()->equals($assignment->getAcademicClass()->getId())) {
                    throw new \InvalidArgumentException('Section does not belong to the selected class.');
                }

                $assignment->setSection($section);
            }
        }

        if ($request->subjectId !== null) {
            if ($request->subjectId === '') {
                $assignment->setSubject(null);
            } else {
                $subject = $this->entityManager->getRepository(Subject::class)->find($request->subjectId);

                if (!$subject instanceof Subject) {
                    throw new \InvalidArgumentException('Subject not found.');
                }

                if (!$subject->getInstitute()->getId()->equals($assignment->getAcademicClass()->getInstitute()->getId())) {
                    throw new \InvalidArgumentException('Subject does not belong to the authenticated institute.');
                }

                $assignment->setSubject($subject);
            }
        }

        if ($request->assignmentType !== null) {
            $assignment->setAssignmentType($request->assignmentType);
        }

        if ($request->remarks !== null) {
            $assignment->setRemarks($request->remarks);
        }

        if ($request->status !== null) {
            $assignment->setStatus(TeacherAssignmentStatus::from($request->status));
        }
    }

    private function mapToResponse(TeacherAssignment $assignment): TeacherAssignmentResponseDTO
    {
        $teacher = $assignment->getTeacher();
        $academicYear = $assignment->getAcademicYear();
        $academicClass = $assignment->getAcademicClass();
        $section = $assignment->getSection();
        $subject = $assignment->getSubject();

        return new TeacherAssignmentResponseDTO(
            id: (string) $assignment->getId(),
            teacher: new TeacherResponse(
                id: (string) $teacher->getId(),
                name: trim($teacher->getFirstName() . ' ' . $teacher->getLastName()),
                email: $teacher->getEmail(),
            ),
            academicYear: new AcademicYearResponse(
                id: (string) $academicYear->getId(),
                name: $academicYear->getName(),
            ),
            academicClass: new AcademicClassResponse(
                id: (string) $academicClass->getId(),
                name: $academicClass->getName(),
                code: $academicClass->getCode(),
            ),
            section: $section !== null ? new SectionResponse(
                id: (string) $section->getId(),
                name: $section->getName(),
                code: $section->getCode(),
            ) : null,
            subject: $subject !== null ? new SubjectResponse(
                id: (string) $subject->getId(),
                name: $subject->getName(),
                code: $subject->getCode(),
            ) : null,
            assignmentType: $assignment->getAssignmentType(),
            remarks: $assignment->getRemarks(),
            status: $assignment->getStatus()->value,
            createdAt: $assignment->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $assignment->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }

    private function validateTeacher(User $teacher, ?Uuid $instituteId): void
    {
        if (!in_array(UserRole::Teacher->value, $teacher->getRoles(), true)) {
            throw new \InvalidArgumentException('Selected user is not a teacher.');
        }

        if ($instituteId !== null && !$teacher->getInstitute() instanceof Institute) {
            throw new \InvalidArgumentException('Teacher does not belong to any institute.');
        }

        if ($instituteId !== null && !$teacher->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Teacher does not belong to your institute.');
        }
    }

    private function validateAcademicYear(AcademicYear $academicYear, ?Uuid $instituteId): void
    {
        if ($instituteId !== null && !$academicYear->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Selected Academic Year does not belong to your institute.');
        }
    }
}
