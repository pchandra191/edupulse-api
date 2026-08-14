<?php
declare(strict_types=1);

namespace App\Service\StudentEnrollmentModule;

use App\DTO\StudentEnrollmentModule\AcademicClassResponse;
use App\DTO\StudentEnrollmentModule\AcademicYearResponse;
use App\DTO\StudentEnrollmentModule\CreateEnrollmentSectionRequestDTO;
use App\DTO\StudentEnrollmentModule\CreateStudentEnrollmentRequestDTO;
use App\DTO\StudentEnrollmentModule\InstituteResponse;
use App\DTO\StudentEnrollmentModule\SectionResponse;
use App\DTO\StudentEnrollmentModule\StudentEnrollmentFilterRequest;
use App\DTO\StudentEnrollmentModule\StudentEnrollmentResponseDTO;
use App\DTO\StudentEnrollmentModule\StudentResponse;
use App\DTO\StudentEnrollmentModule\UpdateStudentEnrollmentRequestDTO;
use App\DTO\Response\StudentEnrollmentListResponse;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\EnrollmentSection;
use App\Entity\Institute;
use App\Entity\Section;
use App\Entity\StudentEnrollment;
use App\Entity\User;
use App\Enum\EnrollmentSectionStatus;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\UserRole;
use App\Exception\AuthenticationException;
use App\Repository\EnrollmentSectionRepository;
use App\Repository\InstituteRepository;
use App\Repository\StudentEnrollmentRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

readonly class StudentEnrollmentService
{
    public function __construct(
        private Security $security,
        private StudentEnrollmentRepository $studentEnrollmentRepository,
        private EnrollmentSectionRepository $enrollmentSectionRepository,
        private InstituteRepository $instituteRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function getStudentEnrollments(?Uuid $instituteId, StudentEnrollmentFilterRequest $filter): StudentEnrollmentListResponse
    {
        $result = $this->studentEnrollmentRepository->findPaginated(
            $instituteId,
            $filter->studentId ? Uuid::fromString($filter->studentId) : null,
            $filter->academicYearId ? Uuid::fromString($filter->academicYearId) : null,
            $filter->classId ? Uuid::fromString($filter->classId) : null,
            $filter->sectionId ? Uuid::fromString($filter->sectionId) : null,
            $filter->search,
            $filter->status,
            $filter->page,
            $filter->limit,
            $filter->sortBy,
            $filter->sortOrder,
        );

        $items = array_map(
            fn (StudentEnrollment $enrollment) => $this->mapToResponse($enrollment),
            $result['items'],
        );

        return new StudentEnrollmentListResponse(
            items: $items,
            pagination: $result['pagination'],
        );
    }

    public function getStudentEnrollment(Uuid $id, ?Uuid $instituteId): StudentEnrollmentResponseDTO
    {
        $enrollment = $this->studentEnrollmentRepository->findById($id, $instituteId);

        if (!$enrollment instanceof StudentEnrollment) {
            throw new AuthenticationException('Student enrollment not found.');
        }

        return $this->mapToResponse($enrollment);
    }

    public function createStudentEnrollment(Uuid $instituteId, CreateStudentEnrollmentRequestDTO $request): StudentEnrollmentResponseDTO
    {
        $student = $this->entityManager->getRepository(User::class)->find($request->studentId);

        if (!$student instanceof User) {
            throw new \InvalidArgumentException('Student not found.');
        }

        $this->validateStudent($student, $instituteId);

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

        $admissionDate = null;

        if ($request->admissionDate !== null && $request->admissionDate !== '') {
            $admissionDate = \DateTimeImmutable::createFromFormat('Y-m-d', $request->admissionDate);

            if (!$admissionDate) {
                throw new \InvalidArgumentException('Invalid admission date format. Expected YYYY-MM-DD.');
            }
        }

        if ($this->studentEnrollmentRepository->hasActiveEnrollment($student->getId(), $academicYear->getId(), $academicClass->getId())) {
            throw new \App\Common\Exception\BusinessException('Student already has an enrollment for this academic year.');
        }

        if ($request->rollNumber !== null && $request->rollNumber !== '') {
            if ($this->studentEnrollmentRepository->rollNumberExistsForClass($request->rollNumber, $academicYear->getId(), $academicClass->getId())) {
                throw new \App\Common\Exception\BusinessException('Roll number already exists for this class in the selected academic year.');
            }
        }

        if ($this->studentEnrollmentRepository->admissionNumberExistsForInstitute($request->admissionNumber, $academicClass->getInstitute()->getId(), $academicYear->getId())) {
            throw new \App\Common\Exception\BusinessException('Admission number already exists for this institute in the selected academic year.');
        }

        try {
            $this->entityManager->beginTransaction();

            $enrollment = new StudentEnrollment();
            $enrollment->setStudent($student);
            $enrollment->setAcademicYear($academicYear);
            $enrollment->setAcademicClass($academicClass);
            $enrollment->setInstituteId($academicClass->getInstitute()->getId());
            $enrollment->setRollNumber($request->rollNumber);
            $enrollment->setAdmissionNumber($request->admissionNumber);
            $enrollment->setAdmissionDate($admissionDate);
            $enrollment->setRemarks($request->remarks);
            $enrollment->setIsCurrent($request->isCurrent);
            $enrollment->setStatus(StudentEnrollmentStatus::from($request->status));

            if ($request->isCurrent) {
                $this->deactivatePreviousEnrollments($student->getId());
            }

            $this->entityManager->persist($enrollment);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($enrollment);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to create student enrollment.', [
                'student_id' => $request->studentId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to create student enrollment. Please check the data and try again.', previous: $e);
        }
    }

    public function updateStudentEnrollment(Uuid $id, ?Uuid $instituteId, UpdateStudentEnrollmentRequestDTO $request): StudentEnrollmentResponseDTO
    {
        $enrollment = $this->studentEnrollmentRepository->findById($id, $instituteId);

        if (!$enrollment instanceof StudentEnrollment) {
            throw new AuthenticationException('Student enrollment not found.');
        }

        if ($request->admissionNumber !== null && $request->admissionNumber !== $enrollment->getAdmissionNumber()) {
            if ($this->studentEnrollmentRepository->admissionNumberExistsForInstitute($request->admissionNumber, $enrollment->getAcademicClass()->getInstitute()->getId(), $enrollment->getAcademicYear()->getId(), $enrollment->getId())) {
                throw new \App\Common\Exception\BusinessException('Admission number already exists for this institute in the selected academic year.');
            }
        }

        try {
            $this->entityManager->beginTransaction();
            $this->updateFields($enrollment, $request);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($enrollment);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to update student enrollment.', [
                'enrollment_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to update student enrollment. Please check the data and try again.', previous: $e);
        }
    }

    public function deleteStudentEnrollment(Uuid $id, ?Uuid $instituteId): void
    {
        $enrollment = $this->studentEnrollmentRepository->findById($id, $instituteId);

        if (!$enrollment instanceof StudentEnrollment) {
            throw new AuthenticationException('Student enrollment not found.');
        }

        try {
            $enrollment->setStatus(StudentEnrollmentStatus::Archived);
            $enrollment->setIsCurrent(false);

            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete student enrollment.', [
                'enrollment_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to delete student enrollment. Please try again.', previous: $e);
        }
    }

    public function getEnrollmentSections(Uuid $enrollmentId, ?Uuid $instituteId): array
    {
        $enrollment = $this->studentEnrollmentRepository->findById($enrollmentId, $instituteId);

        if (!$enrollment instanceof StudentEnrollment) {
            throw new AuthenticationException('Student enrollment not found.');
        }

        $sections = $this->enrollmentSectionRepository->findByEnrollment($enrollmentId);

        return array_map(
            fn (EnrollmentSection $es) => new \App\DTO\StudentEnrollmentModule\SectionResponse(
                id: (string) $es->getSection()->getId(),
                name: $es->getSection()->getName(),
                code: $es->getSection()->getCode(),
                status: $es->getStatus()->value,
            ),
            $sections,
        );
    }

    public function addEnrollmentSection(Uuid $enrollmentId, CreateEnrollmentSectionRequestDTO $request, ?Uuid $instituteId): void
    {
        $enrollment = $this->studentEnrollmentRepository->findById($enrollmentId, $instituteId);

        if (!$enrollment instanceof StudentEnrollment) {
            throw new AuthenticationException('Student enrollment not found.');
        }

        $section = $this->entityManager->getRepository(Section::class)->find($request->sectionId);

        if (!$section instanceof Section) {
            throw new \InvalidArgumentException('Section not found.');
        }

        if (!$section->getAcademicClass()->getId()->equals($enrollment->getAcademicClass()->getId())) {
            throw new \InvalidArgumentException('Section does not belong to the selected class.');
        }

        if ($instituteId !== null && !$section->getAcademicClass()->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Section does not belong to the authenticated institute.');
        }

        $existing = $this->enrollmentSectionRepository->findByEnrollmentAndSection($enrollmentId, $section->getId());

        if ($existing instanceof EnrollmentSection) {
            throw new \App\Common\Exception\BusinessException('Student is already enrolled in this section.');
        }

        try {
            $this->entityManager->beginTransaction();

            $enrollmentSection = new EnrollmentSection();
            $enrollmentSection->setStudentEnrollment($enrollment);
            $enrollmentSection->setSection($section);
            $enrollmentSection->setStatus(EnrollmentSectionStatus::from($request->status));
            $enrollmentSection->setJoinedAt(new \DateTimeImmutable());

            $this->entityManager->persist($enrollmentSection);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to add enrollment section.', [
                'enrollment_id' => (string) $enrollmentId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to add section to enrollment. Please try again.', previous: $e);
        }
    }

    public function removeEnrollmentSection(Uuid $enrollmentId, Uuid $sectionId, ?Uuid $instituteId): void
    {
        $enrollment = $this->studentEnrollmentRepository->findById($enrollmentId, $instituteId);

        if (!$enrollment instanceof StudentEnrollment) {
            throw new AuthenticationException('Student enrollment not found.');
        }

        $enrollmentSection = $this->enrollmentSectionRepository->findByEnrollmentAndSection($enrollmentId, $sectionId);

        if (!$enrollmentSection instanceof EnrollmentSection) {
            throw new AuthenticationException('Enrollment section not found.');
        }

        try {
            $enrollmentSection->setStatus(EnrollmentSectionStatus::Archived);
            $enrollmentSection->setLeftAt(new \DateTimeImmutable());

            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to remove enrollment section.', [
                'enrollment_id' => (string) $enrollmentId,
                'section_id' => (string) $sectionId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to remove section from enrollment. Please try again.', previous: $e);
        }
    }

    private function updateFields(StudentEnrollment $enrollment, UpdateStudentEnrollmentRequestDTO $request): void
    {
        if ($request->rollNumber !== null && $request->rollNumber !== $enrollment->getRollNumber()) {
            if ($this->studentEnrollmentRepository->rollNumberExistsForClass($request->rollNumber, $enrollment->getAcademicYear()->getId(), $enrollment->getAcademicClass()->getId(), $enrollment->getId())) {
                throw new \App\Common\Exception\BusinessException('Roll number already exists for this class in the selected academic year.');
            }

            $enrollment->setRollNumber($request->rollNumber);
        }

        if ($request->admissionNumber !== null && $request->admissionNumber !== $enrollment->getAdmissionNumber()) {
            $enrollment->setAdmissionNumber($request->admissionNumber);
        }

        if ($request->admissionDate !== null) {
            if ($request->admissionDate === '') {
                $enrollment->setAdmissionDate(null);
            } else {
                $admissionDate = \DateTimeImmutable::createFromFormat('Y-m-d', $request->admissionDate);

                if (!$admissionDate) {
                    throw new \InvalidArgumentException('Invalid admission date format. Expected YYYY-MM-DD.');
                }

                $enrollment->setAdmissionDate($admissionDate);
            }
        }

        if ($request->remarks !== null) {
            $enrollment->setRemarks($request->remarks);
        }

        if ($request->isCurrent !== null && $request->isCurrent !== $enrollment->isCurrent()) {
            if ($request->isCurrent) {
                $this->deactivatePreviousEnrollments($enrollment->getStudent()->getId());
            }

            $enrollment->setIsCurrent($request->isCurrent);
        }

        if ($request->status !== null) {
            $enrollment->setStatus(StudentEnrollmentStatus::from($request->status));
        }
    }

    private function deactivatePreviousEnrollments(Uuid $studentId): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->update(StudentEnrollment::class, 'se')
            ->set('se.isCurrent', ':false')
            ->set('se.status', ':promoted')
            ->where('se.student = :student')
            ->andWhere('se.isCurrent = :true')
            ->setParameter('false', false)
            ->setParameter('promoted', StudentEnrollmentStatus::Promoted->value)
            ->setParameter('student', $studentId)
            ->setParameter('true', true);

        $qb->getQuery()->execute();
    }

    private function mapToResponse(StudentEnrollment $enrollment): StudentEnrollmentResponseDTO
    {
        $student = $enrollment->getStudent();
        $academicYear = $enrollment->getAcademicYear();
        $academicClass = $enrollment->getAcademicClass();
        $institute = $academicYear->getInstitute();

        $sections = array_map(
            fn (EnrollmentSection $es) => new SectionResponse(
                id: (string) $es->getSection()->getId(),
                name: $es->getSection()->getName(),
                code: $es->getSection()->getCode(),
                status: $es->getStatus()->value,
            ),
            $enrollment->getEnrollmentSections()->toArray(),
        );

        return new StudentEnrollmentResponseDTO(
            id: (string) $enrollment->getId(),
            institute: new InstituteResponse(
                id: (string) $institute->getId(),
                name: $institute->getName(),
            ),
            student: new StudentResponse(
                id: (string) $student->getId(),
                name: trim($student->getFirstName() . ' ' . $student->getLastName()),
                email: $student->getEmail(),
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
            rollNumber: $enrollment->getRollNumber(),
            admissionNumber: $enrollment->getAdmissionNumber(),
            admissionDate: $enrollment->getAdmissionDate()?->format('Y-m-d'),
            remarks: $enrollment->getRemarks(),
            isCurrent: $enrollment->isCurrent(),
            status: $enrollment->getStatus()->value,
            sections: $sections,
            createdAt: $enrollment->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $enrollment->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }

    private function validateStudent(User $student, ?Uuid $instituteId): void
    {
        if (!in_array(UserRole::Student->value, $student->getRoles(), true)) {
            throw new \InvalidArgumentException('Selected user is not a student.');
        }

        if ($instituteId !== null && !$student->getInstitute() instanceof Institute) {
            throw new \InvalidArgumentException('Student does not belong to any institute.');
        }

        if ($instituteId !== null && !$student->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Student does not belong to your institute.');
        }
    }

    private function validateAcademicYear(AcademicYear $academicYear, ?Uuid $instituteId): void
    {
        if ($instituteId !== null && !$academicYear->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Selected Academic Year does not belong to your institute.');
        }
    }
}
