<?php
declare(strict_types=1);

namespace App\Service\ExaminationModule;

use App\DTO\ExaminationModule\AcademicClassResponse;
use App\DTO\ExaminationModule\AcademicYearResponse;
use App\DTO\ExaminationModule\CreateExamSubjectRequestDTO;
use App\DTO\ExaminationModule\CreateExaminationRequestDTO;
use App\DTO\ExaminationModule\ExaminationFilterRequest;
use App\DTO\ExaminationModule\ExaminationResponseDTO;
use App\DTO\ExaminationModule\ExamSubjectResponseDTO;
use App\DTO\ExaminationModule\InstituteResponse;
use App\DTO\ExaminationModule\SectionResponse;
use App\DTO\ExaminationModule\SubjectResponse;
use App\DTO\ExaminationModule\UpdateExamSubjectRequestDTO;
use App\DTO\ExaminationModule\UpdateExaminationRequestDTO;
use App\DTO\Response\ExamSubjectListResponse;
use App\DTO\Response\ExaminationListResponse;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\ExamSubject;
use App\Entity\Examination;
use App\Entity\Institute;
use App\Entity\Section;
use App\Entity\Subject;
use App\Enum\ExaminationStatus;
use App\Exception\AuthenticationException;
use App\Repository\ExamSubjectRepository;
use App\Repository\ExaminationRepository;
use App\Repository\InstituteRepository;
use DateTimeInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

readonly class ExaminationService
{
    public function __construct(
        private Security $security,
        private ExaminationRepository $examinationRepository,
        private ExamSubjectRepository $examSubjectRepository,
        private InstituteRepository $instituteRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function getExaminations(?Uuid $instituteId, ExaminationFilterRequest $filter): ExaminationListResponse
    {
        $result = $this->examinationRepository->findPaginated(
            $instituteId,
            $filter->search,
            $filter->academicYearId ? Uuid::fromString($filter->academicYearId) : null,
            $filter->academicClassId ? Uuid::fromString($filter->academicClassId) : null,
            $filter->status,
            $filter->startDateFrom,
            $filter->startDateTo,
            $filter->endDateFrom,
            $filter->endDateTo,
            $filter->page,
            $filter->limit,
            $filter->sortBy,
            $filter->sortOrder,
        );

        $items = array_map(
            fn (Examination $examination) => $this->mapToResponse($examination),
            $result['items'],
        );

        return new ExaminationListResponse(
            items: $items,
            pagination: $result['pagination'],
        );
    }

    public function getExamination(Uuid $id, ?Uuid $instituteId): ExaminationResponseDTO
    {
        $examination = $this->examinationRepository->findById($id, $instituteId);

        if (!$examination instanceof Examination) {
            throw new AuthenticationException('Examination not found.');
        }

        return $this->mapToResponse($examination);
    }

    public function createExamination(Uuid $instituteId, CreateExaminationRequestDTO $request): ExaminationResponseDTO
    {
        $institute = $this->instituteRepository->find($instituteId);

        if (!$institute instanceof Institute) {
            throw new AuthenticationException('Institute not found.');
        }

        $academicYear = $this->entityManager->getRepository(AcademicYear::class)->find($request->academicYearId);

        if (!$academicYear instanceof AcademicYear) {
            throw new \InvalidArgumentException('Academic year not found.');
        }

        if (!$academicYear->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Academic year does not belong to the authenticated institute.');
        }

        $academicClass = $this->entityManager->getRepository(AcademicClass::class)->find($request->academicClassId);

        if (!$academicClass instanceof AcademicClass) {
            throw new \InvalidArgumentException('Academic class not found.');
        }

        if (!$academicClass->getAcademicYear()->getId()->equals($academicYear->getId())) {
            throw new \InvalidArgumentException('Academic class does not belong to the selected academic year.');
        }

        if (!$academicClass->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Academic class does not belong to the authenticated institute.');
        }

        if ($this->examinationRepository->codeExistsForInstitute($request->code, $instituteId)) {
            throw new \App\Common\Exception\BusinessException('Examination code already exists for this institute.');
        }

        $startDate = \DateTimeImmutable::createFromFormat('Y-m-d', $request->startDate);
        $endDate = \DateTimeImmutable::createFromFormat('Y-m-d', $request->endDate);

        if (!$startDate || !$endDate) {
            throw new \InvalidArgumentException('Invalid date format. Expected YYYY-MM-DD.');
        }

        if ($startDate > $endDate) {
            throw new \InvalidArgumentException('Start date cannot be after end date.');
        }

        try {
            $this->entityManager->beginTransaction();

            $examination = new Examination();
            $examination->setInstitute($institute);
            $examination->setAcademicYear($academicYear);
            $examination->setAcademicClass($academicClass);
            $examination->setName($request->name);
            $examination->setCode($request->code);
            $examination->setDescription($request->description);
            $examination->setStartDate($startDate);
            $examination->setEndDate($endDate);
            $examination->setStatus(ExaminationStatus::from($request->status));

            $this->entityManager->persist($examination);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($examination);
        } catch (\ValueError $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw new \InvalidArgumentException('Invalid status value.', previous: $e);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to create examination.', [
                'institute_id' => (string) $instituteId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to create examination. Please try again.', previous: $e);
        }
    }

    public function updateExamination(Uuid $id, ?Uuid $instituteId, UpdateExaminationRequestDTO $request): ExaminationResponseDTO
    {
        $examination = $this->examinationRepository->findById($id, $instituteId);

        if (!$examination instanceof Examination) {
            throw new AuthenticationException('Examination not found.');
        }

        try {
            $this->entityManager->beginTransaction();
            $this->updateFields($examination, $request);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($examination);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to update examination.', [
                'examination_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to update examination. Please try again.', previous: $e);
        }
    }

    public function deleteExamination(Uuid $id, ?Uuid $instituteId): void
    {
        $examination = $this->examinationRepository->findById($id, $instituteId);

        if (!$examination instanceof Examination) {
            throw new AuthenticationException('Examination not found.');
        }

        try {
            $examination->setStatus(ExaminationStatus::Archived);

            foreach ($examination->getExamSubjects() as $examSubject) {
                $examSubject->setStatus(ExaminationStatus::Archived);
            }

            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete examination.', [
                'examination_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to delete examination. Please try again.', previous: $e);
        }
    }

    public function getExamSubjects(Uuid $examinationId, ?Uuid $instituteId, ?string $search, ?string $status, int $page, int $limit, string $sortBy, string $sortOrder): ExamSubjectListResponse
    {
        $result = $this->examSubjectRepository->findPaginatedByExamination(
            $examinationId,
            $instituteId,
            $search,
            $status,
            $page,
            $limit,
            $sortBy,
            $sortOrder,
        );

        $items = array_map(
            fn (ExamSubject $examSubject) => $this->mapExamSubjectToResponse($examSubject),
            $result['items'],
        );

        return new ExamSubjectListResponse(
            items: $items,
            pagination: $result['pagination'],
        );
    }

    public function getExamSubject(Uuid $examinationId, Uuid $examSubjectId, ?Uuid $instituteId): ExamSubjectResponseDTO
    {
        $examSubject = $this->examSubjectRepository->findById($examSubjectId, $instituteId);

        if (!$examSubject instanceof ExamSubject) {
            throw new AuthenticationException('Exam subject not found.');
        }

        if (!$examSubject->getExamination()->getId()->equals($examinationId)) {
            throw new AuthenticationException('Exam subject not found.');
        }

        return $this->mapExamSubjectToResponse($examSubject);
    }

    public function createExamSubject(Uuid $examinationId, CreateExamSubjectRequestDTO $request, ?Uuid $instituteId): ExamSubjectResponseDTO
    {
        $examination = $this->examinationRepository->findById($examinationId, $instituteId);

        if (!$examination instanceof Examination) {
            throw new AuthenticationException('Examination not found.');
        }

        $subject = $this->entityManager->getRepository(Subject::class)->find($request->subjectId);

        if (!$subject instanceof Subject) {
            throw new \InvalidArgumentException('Subject not found.');
        }

        if ($instituteId !== null && !$subject->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Subject does not belong to the authenticated institute.');
        }

        $section = null;

        if ($request->sectionId !== null && $request->sectionId !== '') {
            $section = $this->entityManager->getRepository(Section::class)->find($request->sectionId);

            if (!$section instanceof Section) {
                throw new \InvalidArgumentException('Section not found.');
            }

            if (!$section->getAcademicClass()->getId()->equals($examination->getAcademicClass()->getId())) {
                throw new \InvalidArgumentException('Section does not belong to the selected class.');
            }

            if ($instituteId !== null && !$section->getAcademicClass()->getInstitute()->getId()->equals($instituteId)) {
                throw new \InvalidArgumentException('Section does not belong to the authenticated institute.');
            }
        }

        if ($this->examSubjectRepository->existsByExaminationSubjectAndSection($examinationId, $subject->getId(), $section?->getId())) {
            throw new \App\Common\Exception\BusinessException('This subject is already scheduled for the examination with the selected section.');
        }

        $examDate = null;
        if ($request->examDate !== null && $request->examDate !== '') {
            $examDate = \DateTimeImmutable::createFromFormat('Y-m-d', $request->examDate);

            if (!$examDate) {
                throw new \InvalidArgumentException('Invalid exam date format. Expected YYYY-MM-DD.');
            }
        }

        $startTime = null;
        if ($request->startTime !== null && $request->startTime !== '') {
            $startTime = \DateTimeImmutable::createFromFormat('H:i', $request->startTime);

            if (!$startTime) {
                throw new \InvalidArgumentException('Invalid start time format. Expected HH:MM.');
            }
        }

        $endTime = null;
        if ($request->endTime !== null && $request->endTime !== '') {
            $endTime = \DateTimeImmutable::createFromFormat('H:i', $request->endTime);

            if (!$endTime) {
                throw new \InvalidArgumentException('Invalid end time format. Expected HH:MM.');
            }
        }

        if (($startTime !== null || $endTime !== null) && ($startTime === null || $endTime === null)) {
            throw new \InvalidArgumentException('Both start time and end time must be provided together.');
        }

        if ($startTime !== null && $endTime !== null && $startTime >= $endTime) {
            throw new \InvalidArgumentException('Start time must be before end time.');
        }

        $duration = $request->duration;

        if ($startTime !== null && $endTime !== null && $duration === null) {
            $durationMinutes = $endTime->getTimestamp() - $startTime->getTimestamp();
            $duration = (int) round($durationMinutes / 60);
        }

        if ($startTime === null && $endTime === null && ($duration === null || $duration <= 0)) {
            throw new \InvalidArgumentException('Either start/end times or a positive duration must be provided.');
        }

        if ($request->maxMarks !== null && $request->passingMarks !== null && $request->passingMarks > $request->maxMarks) {
            throw new \InvalidArgumentException('Passing marks cannot exceed maximum marks.');
        }

        if ($examDate !== null) {
            $examDateOnly = $examDate->format('Y-m-d');
            $startDate = $examination->getStartDate()->format('Y-m-d');
            $endDate = $examination->getEndDate()->format('Y-m-d');

            if ($examDateOnly < $startDate || $examDateOnly > $endDate) {
                throw new \InvalidArgumentException('Exam date must be within the examination date range.');
            }
        }

        try {
            $this->entityManager->beginTransaction();

            $examSubject = new ExamSubject();
            $examSubject->setExamination($examination);
            $examSubject->setSubject($subject);
            $examSubject->setSection($section);
            $examSubject->setExamDate($examDate);
            $examSubject->setStartTime($startTime);
            $examSubject->setEndTime($endTime);
            $examSubject->setDuration($duration);
            $examSubject->setMaxMarks($request->maxMarks);
            $examSubject->setPassingMarks($request->passingMarks);
            $examSubject->setStatus(ExaminationStatus::from($request->status));
            $examSubject->setRemarks($request->remarks);

            $this->entityManager->persist($examSubject);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapExamSubjectToResponse($examSubject);
        } catch (UniqueConstraintViolationException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw new \App\Common\Exception\BusinessException('This subject is already scheduled for the examination with the selected section.', previous: $e);
        } catch (\ValueError $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw new \InvalidArgumentException('Invalid status value.', previous: $e);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to create exam subject.', [
                'examination_id' => (string) $examinationId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to create exam subject. Please try again.', previous: $e);
        }
    }

    public function updateExamSubject(Uuid $examinationId, Uuid $examSubjectId, ?Uuid $instituteId, UpdateExamSubjectRequestDTO $request): ExamSubjectResponseDTO
    {
        $examSubject = $this->examSubjectRepository->findById($examSubjectId, $instituteId);

        if (!$examSubject instanceof ExamSubject) {
            throw new AuthenticationException('Exam subject not found.');
        }

        if (!$examSubject->getExamination()->getId()->equals($examinationId)) {
            throw new AuthenticationException('Exam subject not found.');
        }

        try {
            $this->entityManager->beginTransaction();
            $this->updateExamSubjectFields($examSubject, $request);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapExamSubjectToResponse($examSubject);
        } catch (\ValueError $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw new \InvalidArgumentException('Invalid status value.', previous: $e);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to update exam subject.', [
                'exam_subject_id' => (string) $examSubjectId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to update exam subject. Please try again.', previous: $e);
        }
    }

    public function deleteExamSubject(Uuid $examinationId, Uuid $examSubjectId, ?Uuid $instituteId): void
    {
        $examSubject = $this->examSubjectRepository->findById($examSubjectId, $instituteId);

        if (!$examSubject instanceof ExamSubject) {
            throw new AuthenticationException('Exam subject not found.');
        }

        if (!$examSubject->getExamination()->getId()->equals($examinationId)) {
            throw new AuthenticationException('Exam subject not found.');
        }

        try {
            $examSubject->setStatus(ExaminationStatus::Archived);

            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete exam subject.', [
                'exam_subject_id' => (string) $examSubjectId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to delete exam subject. Please try again.', previous: $e);
        }
    }

    private function updateFields(Examination $examination, UpdateExaminationRequestDTO $request): void
    {
        if ($request->name !== null && $request->name !== $examination->getName()) {
            $examination->setName($request->name);
        }

        if ($request->code !== null && $request->code !== $examination->getCode()) {
            $instituteId = $examination->getInstitute()->getId();

            if ($this->examinationRepository->codeExistsForInstitute($request->code, $instituteId, $examination->getId())) {
                throw new \App\Common\Exception\BusinessException('Examination code already exists for this institute.');
            }

            $examination->setCode($request->code);
        }

        if ($request->description !== null && $request->description !== $examination->getDescription()) {
            $examination->setDescription($request->description);
        }

        if ($request->startDate !== null && $request->startDate !== $examination->getStartDate()->format('Y-m-d')) {
            $startDate = \DateTimeImmutable::createFromFormat('Y-m-d', $request->startDate);

            if (!$startDate) {
                throw new \InvalidArgumentException('Invalid start date format. Expected YYYY-MM-DD.');
            }

            $examination->setStartDate($startDate);
        }

        if ($request->endDate !== null && $request->endDate !== $examination->getEndDate()->format('Y-m-d')) {
            $endDate = \DateTimeImmutable::createFromFormat('Y-m-d', $request->endDate);

            if (!$endDate) {
                throw new \InvalidArgumentException('Invalid end date format. Expected YYYY-MM-DD.');
            }

            $examination->setEndDate($endDate);
        }

        $startDate = $examination->getStartDate();
        $endDate = $examination->getEndDate();

        if ($startDate > $endDate) {
            throw new \InvalidArgumentException('Start date cannot be after end date.');
        }

        if ($request->status !== null) {
            $examination->setStatus(ExaminationStatus::from($request->status));
        }
    }

    private function updateExamSubjectFields(ExamSubject $examSubject, UpdateExamSubjectRequestDTO $request): void
    {
        if ($request->subjectId !== null && $request->subjectId !== $examSubject->getSubject()->getId()->toRfc4122()) {
            $subject = $this->entityManager->getRepository(Subject::class)->find($request->subjectId);

            if (!$subject instanceof Subject) {
                throw new \InvalidArgumentException('Subject not found.');
            }

            $instituteId = $examSubject->getExamination()->getInstitute()->getId();

            if (!$subject->getInstitute()->getId()->equals($instituteId)) {
                throw new \InvalidArgumentException('Subject does not belong to the authenticated institute.');
            }

            $examSubject->setSubject($subject);
        }

        if ($request->sectionId !== null && $request->sectionId !== ($examSubject->getSection()?->getId()->toRfc4122() ?? null)) {
            $section = null;

            if ($request->sectionId !== '') {
                $section = $this->entityManager->getRepository(Section::class)->find($request->sectionId);

                if (!$section instanceof Section) {
                    throw new \InvalidArgumentException('Section not found.');
                }

                if (!$section->getAcademicClass()->getId()->equals($examSubject->getExamination()->getAcademicClass()->getId())) {
                    throw new \InvalidArgumentException('Section does not belong to the selected class.');
                }
            }

            $examSubject->setSection($section);
        }

        if ($request->examDate !== null && $request->examDate !== ($examSubject->getExamDate()?->format('Y-m-d') ?? null)) {
            $examDate = \DateTimeImmutable::createFromFormat('Y-m-d', $request->examDate);

            if (!$examDate) {
                throw new \InvalidArgumentException('Invalid exam date format. Expected YYYY-MM-DD.');
            }

            $examination = $examSubject->getExamination();
            $examDateOnly = $examDate->format('Y-m-d');
            $startDate = $examination->getStartDate()->format('Y-m-d');
            $endDate = $examination->getEndDate()->format('Y-m-d');

            if ($examDateOnly < $startDate || $examDateOnly > $endDate) {
                throw new \InvalidArgumentException('Exam date must be within the examination date range.');
            }

            $examSubject->setExamDate($examDate);
        }

        if ($request->startTime !== null && $request->startTime !== ($examSubject->getStartTime()?->format('H:i') ?? null)) {
            $startTime = $request->startTime !== '' ? \DateTimeImmutable::createFromFormat('H:i', $request->startTime) : null;

            if ($request->startTime !== '' && !$startTime) {
                throw new \InvalidArgumentException('Invalid start time format. Expected HH:MM.');
            }

            $examSubject->setStartTime($startTime);
        }

        if ($request->endTime !== null && $request->endTime !== ($examSubject->getEndTime()?->format('H:i') ?? null)) {
            $endTime = $request->endTime !== '' ? \DateTimeImmutable::createFromFormat('H:i', $request->endTime) : null;

            if ($request->endTime !== '' && !$endTime) {
                throw new \InvalidArgumentException('Invalid end time format. Expected HH:MM.');
            }

            $examSubject->setEndTime($endTime);
        }

        $startTime = $examSubject->getStartTime();
        $endTime = $examSubject->getEndTime();

        if (($startTime !== null || $endTime !== null) && ($startTime === null || $endTime === null)) {
            throw new \InvalidArgumentException('Both start time and end time must be provided together.');
        }

        if ($startTime !== null && $endTime !== null && $startTime >= $endTime) {
            throw new \InvalidArgumentException('Start time must be before end time.');
        }

        $duration = $request->duration;

        if ($startTime !== null && $endTime !== null && $duration === null) {
            $durationMinutes = $endTime->getTimestamp() - $startTime->getTimestamp();
            $duration = (int) round($durationMinutes / 60);
        }

        if ($duration !== null && $duration !== $examSubject->getDuration()) {
            if ($duration <= 0) {
                throw new \InvalidArgumentException('Duration must be a positive integer.');
            }

            $examSubject->setDuration($duration);
        }

        if ($request->maxMarks !== null && $request->maxMarks !== $examSubject->getMaxMarks()) {
            if ($request->maxMarks < 0) {
                throw new \InvalidArgumentException('Maximum marks cannot be negative.');
            }

            $examSubject->setMaxMarks($request->maxMarks);
        }

        if ($request->passingMarks !== null && $request->passingMarks !== $examSubject->getPassingMarks()) {
            if ($request->passingMarks < 0) {
                throw new \InvalidArgumentException('Passing marks cannot be negative.');
            }

            $examSubject->setPassingMarks($request->passingMarks);
        }

        if ($examSubject->getMaxMarks() !== null && $examSubject->getPassingMarks() !== null && $examSubject->getPassingMarks() > $examSubject->getMaxMarks()) {
            throw new \InvalidArgumentException('Passing marks cannot exceed maximum marks.');
        }

        if ($request->status !== null) {
            $examSubject->setStatus(ExaminationStatus::from($request->status));
        }

        if ($request->remarks !== null && $request->remarks !== $examSubject->getRemarks()) {
            $examSubject->setRemarks($request->remarks);
        }
    }

    private function mapToResponse(Examination $examination): ExaminationResponseDTO
    {
        $institute = $examination->getInstitute();
        $academicYear = $examination->getAcademicYear();
        $academicClass = $examination->getAcademicClass();

        return new ExaminationResponseDTO(
            id: (string) $examination->getId(),
            instituteId: (string) $institute->getId(),
            instituteName: $institute->getName(),
            academicYearId: (string) $academicYear->getId(),
            academicYearName: $academicYear->getName(),
            academicClassId: (string) $academicClass->getId(),
            academicClassName: $academicClass->getName(),
            name: $examination->getName(),
            code: $examination->getCode(),
            description: $examination->getDescription(),
            startDate: $examination->getStartDate()->format('Y-m-d'),
            endDate: $examination->getEndDate()->format('Y-m-d'),
            status: $examination->getStatus()->value,
            subjectsCount: $examination->getExamSubjects()->count(),
            createdAt: $examination->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $examination->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }

    private function mapExamSubjectToResponse(ExamSubject $examSubject): ExamSubjectResponseDTO
    {
        $subject = $examSubject->getSubject();
        $section = $examSubject->getSection();

        return new ExamSubjectResponseDTO(
            id: (string) $examSubject->getId(),
            examinationId: (string) $examSubject->getExamination()->getId(),
            subjectId: (string) $subject->getId(),
            subjectName: $subject->getName(),
            subjectCode: $subject->getCode(),
            sectionId: $section?->getId()?->toRfc4122(),
            sectionName: $section?->getName(),
            sectionCode: $section?->getCode(),
            examDate: $examSubject->getExamDate()?->format('Y-m-d'),
            startTime: $examSubject->getStartTime()?->format('H:i'),
            endTime: $examSubject->getEndTime()?->format('H:i'),
            duration: $examSubject->getDuration(),
            maxMarks: $examSubject->getMaxMarks(),
            passingMarks: $examSubject->getPassingMarks(),
            status: $examSubject->getStatus()->value,
            remarks: $examSubject->getRemarks(),
            createdAt: $examSubject->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $examSubject->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }
}
