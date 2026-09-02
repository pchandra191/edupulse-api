<?php
declare(strict_types=1);

namespace App\Service\AcademicYear;

use App\DTO\AcademicYear\AcademicYearCreateRequestDTO;
use App\DTO\AcademicYear\AcademicYearFilterRequest;
use App\DTO\AcademicYear\AcademicYearResponseDTO;
use App\DTO\AcademicYear\AcademicYearUpdateRequestDTO;
use App\DTO\AcademicYear\AcademicYearCountsDTO;
use App\DTO\Response\AcademicYearListResponse;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\Institute;
use App\Entity\Section;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassStatus;
use App\Enum\SectionStatus;
use App\Exception\AuthenticationException;
use App\Repository\AcademicClassRepository;
use App\Repository\AcademicYearRepository;
use App\Repository\InstituteRepository;
use App\Repository\SectionRepository;
use App\Repository\SubjectRepository;
use App\Repository\TeacherAssignmentRepository;
use App\Repository\StudentEnrollmentRepository;
use App\Repository\ExaminationRepository;
use App\Repository\QuestionPaperRepository;
use App\Repository\EnrollmentSectionRepository;
use App\Repository\ExamSubjectRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

readonly class AcademicYearService
{
    public function __construct(
        private Security $security,
        private AcademicYearRepository $academicYearRepository,
        private InstituteRepository $instituteRepository,
        private TeacherAssignmentRepository $teacherAssignmentRepository,
        private StudentEnrollmentRepository $studentEnrollmentRepository,
        private ExaminationRepository $examinationRepository,
        private QuestionPaperRepository $questionPaperRepository,
        private EnrollmentSectionRepository $enrollmentSectionRepository,
        private ExamSubjectRepository $examSubjectRepository,
         private AcademicClassRepository $academicClassRepository,
        private SectionRepository $sectionRepository,
        private SubjectRepository $subjectRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function getAcademicYears(?Uuid $instituteId, AcademicYearFilterRequest $filter): AcademicYearListResponse
    {
        $result = $this->academicYearRepository->findPaginated(
            $instituteId,
            $filter->search,
            $filter->status,
            $filter->page,
            $filter->limit,
            $filter->sortBy,
            $filter->sortOrder,
        );

        $items = array_map(
            fn (AcademicYear $academicYear) => $this->mapToResponse($academicYear),
            $result['items'],
        );

        return new AcademicYearListResponse(
            items: $items,
            pagination: $result['pagination'],
        );
    }

    public function getAcademicYear(Uuid $id, ?Uuid $instituteId): AcademicYearResponseDTO
    {
        $academicYear = $this->academicYearRepository->findById($id, $instituteId);

        if (!$academicYear instanceof AcademicYear) {
            throw new AuthenticationException('Academic year not found.');
        }

        return $this->mapToResponse($academicYear, $this->buildCounts($academicYear));
    }

    public function createAcademicYear(Uuid $instituteId, AcademicYearCreateRequestDTO $request): AcademicYearResponseDTO
    {
        $institute = $this->instituteRepository->find($instituteId);

        if (!$institute instanceof Institute) {
            throw new AuthenticationException('Institute not found.');
        }

        $startDate = \DateTimeImmutable::createFromFormat('Y-m-d', $request->startDate);
        $endDate = \DateTimeImmutable::createFromFormat('Y-m-d', $request->endDate);

        if (!$startDate || !$endDate) {
            throw new \InvalidArgumentException('Invalid date format. Expected YYYY-MM-DD.');
        }

        if ($endDate <= $startDate) {
            throw new \InvalidArgumentException('End date must be after start date.');
        }

        if ($this->academicYearRepository->findByInstitute($instituteId)) {
            $existing = $this->academicYearRepository->findByInstitute($instituteId);
            foreach ($existing as $year) {
                if (mb_strtolower($year->getCode()) === mb_strtolower($request->code)) {
                    throw new \App\Common\Exception\BusinessException('Academic year code already exists for this institute.');
                }
            }
        }

        try {
            $this->entityManager->beginTransaction();

            $academicYear = new AcademicYear();
            $academicYear->setInstitute($institute);
            $academicYear->setName($request->name);
            $academicYear->setCode($request->code);
            $academicYear->setStartDate($startDate);
            $academicYear->setEndDate($endDate);
            $academicYear->setIsActive($request->isActive);
            $academicYear->setDescription($request->description);

            if ($request->isActive) {
                $academicYear->setStatus(AcademicYearStatus::Active);
                $this->academicYearRepository->deactivateAllExcept($academicYear);
            } else {
                $academicYear->setStatus(AcademicYearStatus::Inactive);
            }

            $this->entityManager->persist($academicYear);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($academicYear);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to create academic year.', [
                'institute_id' => (string) $instituteId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to create academic year. Please try again.', previous: $e);
        }
    }

    public function updateAcademicYear(Uuid $id, ?Uuid $instituteId, AcademicYearUpdateRequestDTO $request): AcademicYearResponseDTO
    {
        $academicYear = $this->academicYearRepository->findById($id, $instituteId);

        if (!$academicYear instanceof AcademicYear) {
            throw new AuthenticationException('Academic year not found.');
        }

        if ($academicYear->getStatus() === AcademicYearStatus::Archived) {
            throw new \App\Common\Exception\BusinessException('Archived academic years cannot be modified.');
        }

        try {
            $this->entityManager->beginTransaction();
            $this->updateFields($academicYear, $request);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($academicYear);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to update academic year.', [
                'academic_year_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to update academic year. Please try again.', previous: $e);
        }
    }

    public function deleteAcademicYear(Uuid $id, ?Uuid $instituteId): void
    {
        $academicYear = $this->academicYearRepository->findById($id, $instituteId);

        if (!$academicYear instanceof AcademicYear) {
            throw new AuthenticationException('Academic year not found.');
        }

        if ($academicYear->getStatus() === AcademicYearStatus::Archived) {
            throw new \App\Common\Exception\BusinessException('Archived academic years cannot be modified.');
        }

        try {
            $this->entityManager->beginTransaction();

            $this->teacherAssignmentRepository->archiveByAcademicYear($id);
            $this->studentEnrollmentRepository->archiveByAcademicYear($id);

            $enrollmentIds = $this->studentEnrollmentRepository->findIdsByAcademicYear($id);
            if (!empty($enrollmentIds)) {
                $this->enrollmentSectionRepository->archiveByEnrollmentIds($enrollmentIds);
            }

            $this->examinationRepository->archiveByAcademicYear($id);

            $examinationIds = $this->examinationRepository->findIdsByAcademicYear($id);
            if (!empty($examinationIds)) {
                $this->examSubjectRepository->archiveByExaminationIds($examinationIds);
            }

            $this->questionPaperRepository->archiveByAcademicYear($id);
            $this->academicClassRepository->archiveByAcademicYear($id);
            $this->sectionRepository->archiveByAcademicYear($id);

            $academicYear->setStatus(AcademicYearStatus::Archived);
            $academicYear->setIsActive(false);

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to archive academic year.', [
                'academic_year_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to archive academic year. Please try again.', previous: $e);
        }
    }

    private function updateFields(AcademicYear $academicYear, AcademicYearUpdateRequestDTO $request): void
    {
        if ($request->name !== null && $request->name !== $academicYear->getName()) {
            $academicYear->setName($request->name);
        }

        if ($request->code !== null && $request->code !== $academicYear->getCode()) {
            $academicYear->setCode($request->code);
        }

        if ($request->startDate !== null) {
            $startDate = \DateTimeImmutable::createFromFormat('Y-m-d', $request->startDate);

            if (!$startDate) {
                throw new \InvalidArgumentException('Invalid start date format. Expected YYYY-MM-DD.');
            }

            $academicYear->setStartDate($startDate);
        }

        if ($request->endDate !== null) {
            $endDate = \DateTimeImmutable::createFromFormat('Y-m-d', $request->endDate);

            if (!$endDate) {
                throw new \InvalidArgumentException('Invalid end date format. Expected YYYY-MM-DD.');
            }

            if ($endDate <= $academicYear->getStartDate()) {
                throw new \InvalidArgumentException('End date must be after start date.');
            }

            $academicYear->setEndDate($endDate);
        }

        if ($request->isActive !== null && $request->isActive !== $academicYear->isActive()) {
            $academicYear->setIsActive($request->isActive);

            if ($request->isActive) {
                $academicYear->setStatus(AcademicYearStatus::Active);
                $this->academicYearRepository->deactivateAllExcept($academicYear);
            } else {
                $academicYear->setStatus(AcademicYearStatus::Inactive);
            }
        }

        if ($request->description !== null && $request->description !== $academicYear->getDescription()) {
            $academicYear->setDescription($request->description);
        }
    }

    private function mapToResponse(
        AcademicYear $academicYear,
        ?AcademicYearCountsDTO $counts = null
    ): AcademicYearResponseDTO {
        return new AcademicYearResponseDTO(
            id: (string) $academicYear->getId(),
            instituteId: (string) $academicYear->getInstitute()->getId(),
            name: $academicYear->getName(),
            code: $academicYear->getCode(),
            startDate: $academicYear->getStartDate()->format('Y-m-d'),
            endDate: $academicYear->getEndDate()->format('Y-m-d'),
            isActive: $academicYear->isActive(),
            status: $academicYear->getStatus()->value,
            description: $academicYear->getDescription(),
            createdAt: $academicYear->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $academicYear->getUpdatedAt()?->format(DateTimeInterface::ATOM),
            counts: $counts,
        );
    }

    private function buildCounts(AcademicYear $academicYear): AcademicYearCountsDTO
    {
        $yearId = $academicYear->getId();

        return new AcademicYearCountsDTO(
            classesCount: $this->academicClassRepository->countByAcademicYear($yearId),
            sectionsCount: $this->sectionRepository->countByAcademicYear($yearId),
            studentsCount: $this->studentEnrollmentRepository->countByAcademicYear($yearId),
            teachersCount: $this->teacherAssignmentRepository->countDistinctTeachersByAcademicYear($yearId),
            examinationsCount: $this->examinationRepository->countByAcademicYear($yearId),
            questionPapersCount: $this->questionPaperRepository->countByAcademicYear($yearId),
        );
    }
}
