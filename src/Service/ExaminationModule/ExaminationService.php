<?php
declare(strict_types=1);

namespace App\Service\ExaminationModule;

use App\DTO\ExaminationModule\AcademicClassResponse;
use App\DTO\ExaminationModule\AcademicYearResponse;
use App\DTO\ExaminationModule\CreatedSessionDTO;
use App\DTO\ExaminationModule\CreateExamSubjectRequestDTO;
use App\DTO\ExaminationModule\CreateExaminationRequestDTO;
use App\DTO\ExaminationModule\ExaminationFilterRequest;
use App\DTO\ExaminationModule\ExaminationResponseDTO;
use App\DTO\ExaminationModule\ExamSubjectReadinessDTO;
use App\DTO\ExaminationModule\ExaminationReadinessSummaryDTO;
use App\DTO\ExaminationModule\ExamSubjectResponseDTO;
use App\DTO\ExaminationModule\InstituteResponse;
use App\DTO\ExaminationModule\QuestionPaperSummary;
use App\DTO\ExaminationModule\ExamSessionSummary;
use App\DTO\ExaminationModule\SectionResponse;
use App\DTO\ExaminationModule\StartExamSubjectResponseDTO;
use App\DTO\ExaminationModule\SubjectResponse;
use App\DTO\ExaminationModule\UpdateExamSubjectRequestDTO;
use App\DTO\ExaminationModule\UpdateExaminationRequestDTO;
use App\DTO\ExaminationModule\ValidateAndStartExamResponseDTO;
use App\DTO\Response\ExamSubjectListResponse;
use App\DTO\Response\ExaminationListResponse;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\ExamSubject;
use App\Entity\Examination;
use App\Entity\Institute;
use App\Entity\ExamSession;
use App\Entity\QuestionPaper;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\ExaminationStatus;
use App\Enum\ExamSessionStatus;
use App\Enum\QuestionPaperStatus;
use App\Enum\ReadinessReason;
use App\Enum\ReadinessStatus;
use App\Exception\AuthenticationException;
use App\Repository\ExamSubjectRepository;
use App\Repository\ExaminationRepository;
use App\Repository\ExamSessionRepository;
use App\Repository\InstituteRepository;
use App\Repository\UserRepository;
use App\Service\QuestionPaperModule\QuestionPaperService;
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
        private UserRepository $userRepository,
        private QuestionPaperService $questionPaperService,
        private ExamSessionSynchronizer $examSessionSynchronizer,
        private ExamSessionRepository $examSessionRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private \Symfony\Component\Workflow\Registry $workflowRegistry,
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

        $examinationIds = [];
        foreach ($result['items'] as $examination) {
            $examinationIds[] = $examination->getId()->toRfc4122();
        }

        $examSubjectCounts = $this->examinationRepository->findExamSubjectCountsByExaminationIds($examinationIds);

        $items = array_map(
            fn (Examination $examination) => $this->mapToListItemResponse($examination, $examSubjectCounts),
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
            $examination->setStatus(ExaminationStatus::Draft);

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

        if ($examination->getStatus() === ExaminationStatus::Archived) {
            throw new \App\Common\Exception\BusinessException('Archived examinations cannot be modified.');
        }

        try {
            $this->entityManager->beginTransaction();
            $this->updateFields($examination, $request);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($examination);
        } catch (\App\Common\Exception\BusinessException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw $e;
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

        if ($examination->getStatus() === ExaminationStatus::Archived) {
            throw new \App\Common\Exception\BusinessException('Archived examinations cannot be deleted.');
        }

        try {
            $workflow = $this->workflowRegistry->get($examination);

            if (!$workflow->can($examination, 'archive')) {
                throw new \App\Common\Exception\BusinessException('Examination cannot be archived from its current state.');
            }

            $workflow->apply($examination, 'archive');

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
            $duration = $this->calculateDurationInMinutes($startTime, $endTime);
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
            $this->examSessionSynchronizer->synchronize($examSubject);
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

        if ($examSubject->getStatus() === ExaminationStatus::Archived) {
            throw new \App\Common\Exception\BusinessException('Archived exam subjects cannot be modified.');
        }

        if (!$examSubject->getExamination()->getId()->equals($examinationId)) {
            throw new AuthenticationException('Exam subject not found.');
        }

        $originalQuestionPaper = $examSubject->getQuestionPaper();

        try {
            $this->entityManager->beginTransaction();
            $this->updateExamSubjectFields($examSubject, $request);

            if ($examSubject->isValidated()) {
                $examSubject->setValidated(false);
            }

            $this->entityManager->flush();
            $this->examSessionSynchronizer->synchronize($examSubject);
            $this->entityManager->commit();

            if ($examSubject->getQuestionPaper() !== $originalQuestionPaper) {
                $examSubject->setQuestionPaper($originalQuestionPaper);
                $this->entityManager->flush();
            }

            return $this->mapExamSubjectToResponse($examSubject);
        } catch (\ValueError $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw new \InvalidArgumentException('Invalid status value.', previous: $e);
        } catch (\InvalidArgumentException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw $e;
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

        if ($examSubject->getStatus() === ExaminationStatus::Archived) {
            throw new \App\Common\Exception\BusinessException('Archived exam subjects cannot be deleted.');
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

    public function attachQuestionPaperToExamSubject(Uuid $examinationId, Uuid $examSubjectId, ?Uuid $instituteId, Uuid $questionPaperId): ExamSubjectResponseDTO
    {
        $examSubject = $this->examSubjectRepository->findById($examSubjectId, $instituteId);

        if (!$examSubject instanceof ExamSubject) {
            throw new AuthenticationException('Exam subject not found.');
        }

        if (!$examSubject->getExamination()->getId()->equals($examinationId)) {
            throw new AuthenticationException('Exam subject not found.');
        }

        $examination = $examSubject->getExamination();

        $questionPaper = $this->entityManager->getRepository(\App\Entity\QuestionPaper::class)->find($questionPaperId);

        try {
            $this->entityManager->beginTransaction();

        if (!$questionPaper instanceof \App\Entity\QuestionPaper) {
            throw new \InvalidArgumentException('Question paper not found.');
        }

        if (!$questionPaper->getInstitute()->getId()->equals($examination->getInstitute()->getId())) {
            throw new \InvalidArgumentException('Question paper does not belong to the same institute as the examination.');
        }

        if (!$questionPaper->getSubject()->getId()->equals($examSubject->getSubject()->getId())) {
            throw new \InvalidArgumentException('This question paper belongs to ' . $questionPaper->getSubject()->getName() . ' and cannot be attached to ' . $examSubject->getSubject()->getName() . '.');
        }

        if (!$questionPaper->getAcademicYear()->getId()->equals($examination->getAcademicYear()->getId())) {
            throw new \InvalidArgumentException('Question paper does not belong to the same academic year as the examination.');
        }

        $existingPaper = $examSubject->getQuestionPaper();

        if ($existingPaper instanceof \App\Entity\QuestionPaper && $existingPaper->getId()->equals($questionPaperId)) {
            if ($examSubject->getMaxMarks() !== null) {
                $existingPaper->setMaximumMarks($examSubject->getMaxMarks());
            }

            if ($examSubject->getDuration() !== null) {
                $existingPaper->setDuration($examSubject->getDuration());
            }

            $this->entityManager->flush();
            $this->examSessionSynchronizer->synchronize($examSubject);

            return $this->mapExamSubjectToResponse($examSubject);
        }

        $newPaper = $this->questionPaperService->performClone($questionPaper);

        if ($examSubject->getMaxMarks() !== null) {
            $newPaper->setMaximumMarks($examSubject->getMaxMarks());
        }

        if ($examSubject->getDuration() !== null) {
            $newPaper->setDuration($examSubject->getDuration());
        }

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $newPaper->setCreatedBy($user->getId()->toRfc4122());
        }

        $this->entityManager->persist($newPaper);
        $this->entityManager->flush();

        $examSubject->setQuestionPaper($newPaper);
        $this->entityManager->flush();

        $existingSession = $this->examSessionRepository->findByExamSubject(
            $examSubject->getId(),
            $examination->getInstitute()->getId()
        );

        if ($existingSession instanceof ExamSession && $existingSession->getExamAttempts()->isEmpty()) {
            $existingSession->setStatus(ExamSessionStatus::Draft);
            $this->entityManager->flush();
        }

        $this->examSessionSynchronizer->synchronize($examSubject);
        $this->entityManager->commit();

        return $this->mapExamSubjectToResponse($examSubject);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to attach question paper to exam subject.', [
                'exam_subject_id' => (string) $examSubjectId,
                'question_paper_id' => (string) $questionPaperId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to attach question paper. Please try again.', previous: $e);
        }
    }

    public function removeQuestionPaperFromExamSubject(Uuid $examinationId, Uuid $examSubjectId, ?Uuid $instituteId): ExamSubjectResponseDTO
    {
        $examSubject = $this->examSubjectRepository->findById($examSubjectId, $instituteId);

        if (!$examSubject instanceof ExamSubject) {
            throw new AuthenticationException('Exam subject not found.');
        }

        if (!$examSubject->getExamination()->getId()->equals($examinationId)) {
            throw new AuthenticationException('Exam subject not found.');
        }

        try {
            $examSubject->setQuestionPaper(null);
            $this->entityManager->flush();
            $this->examSessionSynchronizer->synchronize($examSubject);

            return $this->mapExamSubjectToResponse($examSubject);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to remove question paper from exam subject.', [
                'exam_subject_id' => (string) $examSubjectId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to remove question paper. Please try again.', previous: $e);
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
            $target = ExaminationStatus::from($request->status);
            $current = $examination->getStatus();

            if ($current !== $target) {
                $transition = $this->resolveExaminationTransition($current, $target);

                if ($transition === null || !$this->workflowRegistry->get($examination)->can($examination, $transition)) {
                    throw new \App\Common\Exception\BusinessException(
                        sprintf('Invalid examination status transition from %s to %s.', $current->value, $target->value)
                    );
                }

                $this->workflowRegistry->get($examination)->apply($examination, $transition);
            }
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

        if ($startTime !== null && $endTime !== null) {
            $startMinutes = ($startTime->format('H') * 60) + (int) $startTime->format('i');
            $endMinutes = ($endTime->format('H') * 60) + (int) $endTime->format('i');

            if ($startMinutes >= $endMinutes) {
                throw new \InvalidArgumentException('Start time must be before end time.');
            }
        }

        $duration = $request->duration;

        if ($startTime !== null && $endTime !== null && $duration === null) {
            $duration = $this->calculateDurationInMinutes($startTime, $endTime);
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

    private function resolveExaminationTransition(ExaminationStatus $from, ExaminationStatus $to): ?string
    {
        return match (true) {
            $from === $to => null,
            $from === ExaminationStatus::Draft && $to === ExaminationStatus::Scheduled => 'schedule',
            $from === ExaminationStatus::Scheduled && $to === ExaminationStatus::Ongoing => 'start',
            $from === ExaminationStatus::Ongoing && $to === ExaminationStatus::Completed => 'complete',
            $from === ExaminationStatus::Completed && $to === ExaminationStatus::Archived => 'archive',
            $from === ExaminationStatus::Draft && $to === ExaminationStatus::Cancelled => 'cancel',
            $from === ExaminationStatus::Scheduled && $to === ExaminationStatus::Cancelled => 'cancel',
            $from === ExaminationStatus::Ongoing && $to === ExaminationStatus::Cancelled => 'cancel',
            $from === ExaminationStatus::Completed && $to === ExaminationStatus::Scheduled => 'reopen',
            default => null,
        };
    }

    private function mapToListItemResponse(Examination $examination, array $examSubjectCounts): ExaminationResponseDTO
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
            readiness: null,
            createdAt: $examination->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $examination->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
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
            readiness: $this->getExaminationReadiness($examination->getId(), $examination->getInstitute()->getId()),
            createdAt: $examination->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $examination->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }

    public function getExaminationReadiness(Uuid $examinationId, ?Uuid $instituteId): ExaminationReadinessSummaryDTO
    {
        $examination = $this->examinationRepository->findById($examinationId, $instituteId);

        if (!$examination instanceof Examination) {
            throw new AuthenticationException('Examination not found.');
        }

        $examSubjects = $this->examSubjectRepository->findByExamination($examinationId);

        $subjectReadiness = [];
        $readyCount = 0;
        $notReadyCount = 0;
        $cancelledCount = 0;
        $activeCount = 0;
        $sessionsCreated = 0;
        $validatedCount = 0;

        foreach ($examSubjects as $examSubject) {
            $status = $examSubject->getStatus();

            if ($status === ExaminationStatus::Archived || $status === ExaminationStatus::Cancelled) {
                if ($status === ExaminationStatus::Cancelled) {
                    $cancelledCount++;
                }

                $session = $this->examSessionRepository->findByExamSubject(
                    $examSubject->getId(),
                    $examination->getInstitute()->getId()
                );

$subjectReadiness[] = new ExamSubjectReadinessDTO(
                    examSubjectId: (string) $examSubject->getId(),
                    subjectName: $examSubject->getSubject()->getName(),
                    sectionName: $examSubject->getSection()?->getName(),
                    examSubjectStatus: $status->value,
                    questionPaper: $this->buildQuestionPaperSummary($examSubject->getQuestionPaper()),
                    readinessStatus: ReadinessStatus::Excluded,
                    readinessReason: null,
                    readyReasonMessage: null,
                    hasSession: $session instanceof ExamSession,
                    examSession: $session instanceof ExamSession
                        ? new ExamSessionSummary(
                            id: (string) $session->getId(),
                            status: $session->getStatus()->value,
                            availableFrom: $session->getAvailableFrom()?->format(DateTimeInterface::ATOM),
                            availableTo: $session->getAvailableTo()?->format(DateTimeInterface::ATOM),
                        )
                        : null,
                    isValidated: $examSubject->isValidated(),
                    isActive: false,
                );

                continue;
            }

            $activeCount++;

            $session = $this->examSessionRepository->findByExamSubject(
                $examSubject->getId(),
                $examination->getInstitute()->getId()
            );

            $readiness = $this->checkQuestionPaperReadiness($examSubject);

$subjectReadiness[] = new ExamSubjectReadinessDTO(
                    examSubjectId: (string) $examSubject->getId(),
                    subjectName: $examSubject->getSubject()->getName(),
                    sectionName: $examSubject->getSection()?->getName(),
                    examSubjectStatus: $status->value,
                    questionPaper: $this->buildQuestionPaperSummary($examSubject->getQuestionPaper()),
                    readinessStatus: $readiness['status'],
                    readinessReason: $readiness['reason'],
                    readyReasonMessage: $readiness['message'],
                    hasSession: $session instanceof ExamSession,
                    examSession: $session instanceof ExamSession
                        ? new ExamSessionSummary(
                            id: (string) $session->getId(),
                            status: $session->getStatus()->value,
                            availableFrom: $session->getAvailableFrom()?->format(DateTimeInterface::ATOM),
                            availableTo: $session->getAvailableTo()?->format(DateTimeInterface::ATOM),
                        )
                        : null,
                    isValidated: $examSubject->isValidated(),
                    isActive: true,
                );

            if ($readiness['status'] === ReadinessStatus::Ready) {
                $readyCount++;
            } else {
                $notReadyCount++;
            }

            if ($session instanceof ExamSession) {
                $sessionsCreated++;
                $validatedCount++;
            }
        }

        $examinationStatus = $examination->getStatus();
        $canValidateAndStart = $activeCount > 0
            && $readyCount === $activeCount
            && $examinationStatus !== ExaminationStatus::Archived
            && $examinationStatus !== ExaminationStatus::Cancelled
            && $examinationStatus !== ExaminationStatus::Completed;

        return new ExaminationReadinessSummaryDTO(
            ready: $notReadyCount === 0 && $activeCount > 0,
            totalSubjects: count($subjectReadiness),
            readySubjects: $readyCount,
            notReadySubjects: $notReadyCount,
            sessionsCreated: $sessionsCreated,
            canValidateAndStart: $canValidateAndStart,
            subjects: $subjectReadiness,
            activeSubjects: $activeCount,
            cancelledSubjects: $cancelledCount,
            validatedSubjects: $validatedCount,
        );
    }

    public function validateAndStartExam(Uuid $examinationId, ?Uuid $instituteId): ValidateAndStartExamResponseDTO
    {
        $examination = $this->examinationRepository->findById($examinationId, $instituteId);

        if (!$examination instanceof Examination) {
            throw new AuthenticationException('Examination not found.');
        }

        $examinationStatus = $examination->getStatus();

        if (in_array($examinationStatus, [ExaminationStatus::Archived, ExaminationStatus::Cancelled, ExaminationStatus::Completed], true)) {
            throw new \App\Common\Exception\BusinessException(
                'Examination cannot be started. Status: ' . $examinationStatus->value,
                ['examinationId' => (string) $examinationId]
            );
        }

        if ($examinationStatus === ExaminationStatus::Draft) {
            throw new \App\Common\Exception\BusinessException(
                'Examination must be scheduled before starting.',
                ['examinationId' => (string) $examinationId]
            );
        }

        $examSubjects = $this->examSubjectRepository->findByExamination($examinationId);

        if (empty($examSubjects)) {
            throw new \InvalidArgumentException('Examination has no exam subjects.');
        }

        $activeSubjects = [];
        foreach ($examSubjects as $examSubject) {
            if ($examSubject->getStatus() !== ExaminationStatus::Archived && $examSubject->getStatus() !== ExaminationStatus::Cancelled) {
                $activeSubjects[] = $examSubject;
            }
        }

        if (empty($activeSubjects)) {
            throw new \InvalidArgumentException('Examination has no active exam subjects.');
        }

        // Phase 1: Validate readiness for every active subject
        $errors = [];
        $subjectResults = [];

        foreach ($activeSubjects as $examSubject) {
            $readiness = $this->checkQuestionPaperReadiness($examSubject);
            $isReady = $readiness['status'] === ReadinessStatus::Ready;
            $reason = $readiness['reason']?->value ?? ReadinessReason::InvalidConfiguration->value;
            $message = $readiness['message'] ?? 'Invalid configuration.';

            $subjectErrors = [];

            if (!$isReady) {
                $subjectErrors[] = [
                    'examSubjectId' => (string) $examSubject->getId(),
                    'subject' => $examSubject->getSubject()->getName(),
                    'reason' => $reason,
                    'message' => $message,
                ];
            }

            $this->validateExamSubjectConfig($examSubject, $subjectErrors);

            $subjectHasErrors = !empty($subjectErrors);
            if ($subjectHasErrors) {
                $errors = array_merge($errors, $subjectErrors);
            }

            $subjectResult = [
                'examSubjectId' => (string) $examSubject->getId(),
                'subject' => $examSubject->getSubject()->getName(),
                'isReady' => !$subjectHasErrors,
                'isValidated' => false,
                'reason' => $subjectHasErrors ? $subjectErrors[0]['reason'] : ReadinessReason::Ready->value,
                'message' => $subjectHasErrors ? $subjectErrors[0]['message'] : null,
                'isActive' => true,
            ];

            $subjectResults[] = $subjectResult;
        }

        if (!empty($errors)) {
            return new ValidateAndStartExamResponseDTO(
                examinationId: (string) $examinationId,
                success: false,
                message: 'Examination is not ready to start.',
                validatedSubjects: 0,
                totalSubjects: count($activeSubjects),
                sessionsCreated: 0,
                sessionsReused: 0,
                sessions: [],
                errors: $errors,
                subjectResults: $subjectResults,
            );
        }

        // Phase 2: All subjects are ready - create/reuse sessions
        $this->entityManager->beginTransaction();

        try {
            $createdSessions = [];
            $sessionsCreated = 0;
            $sessionsReused = 0;
            $validatedCount = 0;

            foreach ($activeSubjects as $index => $examSubject) {
                $existingSession = $this->examSessionRepository->findByExamSubject(
                    $examSubject->getId(),
                    $examination->getInstitute()->getId()
                );

                if ($existingSession instanceof ExamSession) {
                    if ($existingSession->getExamAttempts()->isEmpty()) {
                        $this->examSessionSynchronizer->synchronize($examSubject);
                        $this->entityManager->flush();
                    }

                    $this->examSessionSynchronizer->ensurePublished($existingSession);
                    $examSubject->setValidated(true);

                    $createdSessions[] = new CreatedSessionDTO(
                        examSubjectId: (string) $examSubject->getId(),
                        examSessionId: (string) $existingSession->getId(),
                        status: $existingSession->getStatus()->value,
                        created: false,
                        reused: true,
                        isValidated: true,
                    );
                    $sessionsReused++;
                    $validatedCount++;
                    $subjectResults[$index]['isValidated'] = true;
                    $subjectResults[$index]['sessionId'] = (string) $existingSession->getId();
                    $subjectResults[$index]['sessionCreated'] = false;
                    $subjectResults[$index]['sessionReused'] = true;

                    continue;
                }

                $session = $this->examSessionSynchronizer->synchronize($examSubject);

                if ($session instanceof ExamSession) {
                    $this->examSessionSynchronizer->ensurePublished($session);
                    $examSubject->setValidated(true);

                    $createdSessions[] = new CreatedSessionDTO(
                        examSubjectId: (string) $examSubject->getId(),
                        examSessionId: (string) $session->getId(),
                        status: $session->getStatus()->value,
                        created: true,
                        reused: false,
                        isValidated: true,
                    );
                    $sessionsCreated++;
                    $validatedCount++;
                    $subjectResults[$index]['isValidated'] = true;
                    $subjectResults[$index]['sessionId'] = (string) $session->getId();
                    $subjectResults[$index]['sessionCreated'] = true;
                    $subjectResults[$index]['sessionReused'] = false;
                }
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            return new ValidateAndStartExamResponseDTO(
                examinationId: (string) $examinationId,
                success: true,
                message: 'Examination validated and exam sessions created successfully.',
                validatedSubjects: $validatedCount,
                totalSubjects: count($activeSubjects),
                sessionsCreated: $sessionsCreated,
                sessionsReused: $sessionsReused,
                sessions: $createdSessions,
                errors: [],
                subjectResults: $subjectResults,
            );
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('ValidateAndStartExam: Failed to create exam sessions.', [
                'examination_id' => (string) $examinationId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException(
                'Failed to create exam sessions. Please try again.',
                ['examinationId' => (string) $examinationId],
                0,
                $e
            );
        }
    }

    public function startExamSubject(Uuid $examSubjectId, ?Uuid $instituteId): StartExamSubjectResponseDTO
    {
        $examSubject = $this->examSubjectRepository->find($examSubjectId);

        if (!$examSubject instanceof ExamSubject) {
            return new StartExamSubjectResponseDTO(
                examSubjectId: (string) $examSubjectId,
                success: false,
                message: 'Exam subject not found.',
                examSession: null,
                errors: [
                    [
                        'examSubjectId' => (string) $examSubjectId,
                        'reason' => ReadinessReason::InvalidConfiguration->value,
                        'message' => 'Exam subject not found.',
                    ],
                ],
            );
        }

        $examination = $examSubject->getExamination();
        $subjectInstituteId = $examination->getInstitute()->getId();

        if ($instituteId !== null && !$subjectInstituteId->equals($instituteId)) {
            return new StartExamSubjectResponseDTO(
                examSubjectId: (string) $examSubjectId,
                success: false,
                message: 'Exam subject not found.',
                examSession: null,
                errors: [
                    [
                        'examSubjectId' => (string) $examSubjectId,
                        'reason' => ReadinessReason::InvalidConfiguration->value,
                        'message' => 'Exam subject not found.',
                    ],
                ],
            );
        }

        $examSubjectStatus = $examSubject->getStatus();

        if ($examSubjectStatus !== ExaminationStatus::Scheduled) {
            return new StartExamSubjectResponseDTO(
                examSubjectId: (string) $examSubjectId,
                success: false,
                message: 'Exam subject is not scheduled.',
                examSession: null,
                errors: [
                    [
                        'examSubjectId' => (string) $examSubjectId,
                        'reason' => ReadinessReason::InvalidConfiguration->value,
                        'message' => 'Exam subject must be in SCHEDULED status to start.',
                    ],
                ],
            );
        }

        $paper = $examSubject->getQuestionPaper();

        if (!$paper instanceof QuestionPaper) {
            return new StartExamSubjectResponseDTO(
                examSubjectId: (string) $examSubjectId,
                success: false,
                message: 'Question paper is missing.',
                examSession: null,
                errors: [
                    [
                        'examSubjectId' => (string) $examSubjectId,
                        'reason' => ReadinessReason::QuestionPaperMissing->value,
                        'message' => 'No question paper is attached to this exam subject.',
                    ],
                ],
            );
        }

        if (!$paper->isFinalized()) {
            return new StartExamSubjectResponseDTO(
                examSubjectId: (string) $examSubjectId,
                success: false,
                message: 'Question paper is not finalized.',
                examSession: null,
                errors: [
                    [
                        'examSubjectId' => (string) $examSubjectId,
                        'reason' => ReadinessReason::QuestionPaperNotFinalized->value,
                        'message' => 'Question paper must be in FINALIZED status.',
                    ],
                ],
            );
        }

        $this->entityManager->beginTransaction();

        try {
            $existingSession = $this->examSessionRepository->findByExamSubject(
                $examSubjectId,
                $subjectInstituteId
            );

            if ($existingSession instanceof ExamSession) {
                if ($existingSession->getExamAttempts()->isEmpty()) {
                    $this->examSessionSynchronizer->synchronize($examSubject);
                    $this->entityManager->flush();
                }

                $this->examSessionSynchronizer->ensurePublished($existingSession);
                $examSubject->setValidated(true);
                $this->entityManager->flush();

                $examSession = new ExamSessionSummary(
                    id: (string) $existingSession->getId(),
                    status: $existingSession->getStatus()->value,
                    availableFrom: $existingSession->getAvailableFrom()?->format(DateTimeInterface::ATOM),
                    availableTo: $existingSession->getAvailableTo()?->format(DateTimeInterface::ATOM),
                );

                $this->entityManager->commit();

                return new StartExamSubjectResponseDTO(
                    examSubjectId: (string) $examSubjectId,
                    success: true,
                    message: 'Exam subject started successfully.',
                    examSession: $examSession,
                    errors: [],
                );
            }

            $session = $this->examSessionSynchronizer->synchronize($examSubject);

            if ($session instanceof ExamSession) {
                $this->examSessionSynchronizer->ensurePublished($session);
                $examSubject->setValidated(true);
                $this->entityManager->flush();

                $examSession = new ExamSessionSummary(
                    id: (string) $session->getId(),
                    status: $session->getStatus()->value,
                    availableFrom: $session->getAvailableFrom()?->format(DateTimeInterface::ATOM),
                    availableTo: $session->getAvailableTo()?->format(DateTimeInterface::ATOM),
                );

                $this->entityManager->commit();

                return new StartExamSubjectResponseDTO(
                    examSubjectId: (string) $examSubjectId,
                    success: true,
                    message: 'Exam subject started successfully.',
                    examSession: $examSession,
                    errors: [],
                );
            }

            $this->entityManager->rollback();

            return new StartExamSubjectResponseDTO(
                examSubjectId: (string) $examSubjectId,
                success: false,
                message: 'Failed to create exam session.',
                examSession: null,
                errors: [
                    [
                        'examSubjectId' => (string) $examSubjectId,
                        'reason' => ReadinessReason::InvalidConfiguration->value,
                        'message' => 'Failed to create exam session.',
                    ],
                ],
            );
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('StartExamSubject: Failed to start exam subject.', [
                'exam_subject_id' => (string) $examSubjectId,
                'error' => $e->getMessage(),
            ]);

            return new StartExamSubjectResponseDTO(
                examSubjectId: (string) $examSubjectId,
                success: false,
                message: 'Failed to start exam subject. Please try again.',
                examSession: null,
                errors: [
                    [
                        'examSubjectId' => (string) $examSubjectId,
                        'reason' => ReadinessReason::InvalidConfiguration->value,
                        'message' => 'Failed to start exam subject. Please try again.',
                    ],
                ],
            );
        }
    }

    private function checkQuestionPaperReadiness(ExamSubject $examSubject): array
    {
        $paper = $examSubject->getQuestionPaper();

        if (!$paper instanceof QuestionPaper) {
            return [
                'status' => ReadinessStatus::NotReady,
                'reason' => ReadinessReason::QuestionPaperMissing,
                'message' => 'No question paper is attached to this exam subject.',
            ];
        }

        if (!$paper->isFinalized()) {
            return [
                'status' => ReadinessStatus::NotReady,
                'reason' => ReadinessReason::QuestionPaperNotFinalized,
                'message' => 'Question paper must be in FINALIZED status.',
            ];
        }

        $questionCount = $paper->getQuestions()->count();

        if ($questionCount <= 0) {
            return [
                'status' => ReadinessStatus::NotReady,
                'reason' => ReadinessReason::QuestionPaperEmpty,
                'message' => 'Question paper contains no questions.',
            ];
        }

        $paperMarks = $paper->getMaximumMarks();
        $subjectMaxMarks = $examSubject->getMaxMarks();

        if ($subjectMaxMarks !== null && $subjectMaxMarks > 0 && $paperMarks > 0 && $paperMarks !== $subjectMaxMarks) {
            return [
                'status' => ReadinessStatus::NotReady,
                'reason' => ReadinessReason::MarksMismatch,
                'message' => 'Question paper total marks (' . $paperMarks . ') do not match exam subject maximum marks (' . $subjectMaxMarks . ').',
            ];
        }

        return [
            'status' => ReadinessStatus::Ready,
            'reason' => ReadinessReason::Ready,
            'message' => null,
        ];
    }

    private function validateExamSubjectConfig(ExamSubject $examSubject, array &$errors): void
    {
        $examDate = $examSubject->getExamDate();
        $startTime = $examSubject->getStartTime();
        $endTime = $examSubject->getEndTime();

        if ($examDate === null) {
            $errors[] = [
                'examSubjectId' => (string) $examSubject->getId(),
                'subject' => $examSubject->getSubject()->getName(),
                'reason' => ReadinessReason::InvalidConfiguration->value,
                'message' => 'Exam date is not set.',
            ];

            return;
        }

        if ($startTime === null || $endTime === null) {
            $errors[] = [
                'examSubjectId' => (string) $examSubject->getId(),
                'subject' => $examSubject->getSubject()->getName(),
                'reason' => ReadinessReason::InvalidConfiguration->value,
                'message' => 'Start time and end time must both be set.',
            ];

            return;
        }

        if ($startTime >= $endTime) {
            $errors[] = [
                'examSubjectId' => (string) $examSubject->getId(),
                'subject' => $examSubject->getSubject()->getName(),
                'reason' => ReadinessReason::InvalidConfiguration->value,
                'message' => 'Start time must be before end time.',
            ];

            return;
        }

        if ($examSubject->getMaxMarks() === null || $examSubject->getMaxMarks() <= 0) {
            $errors[] = [
                'examSubjectId' => (string) $examSubject->getId(),
                'subject' => $examSubject->getSubject()->getName(),
                'reason' => ReadinessReason::InvalidConfiguration->value,
                'message' => 'Maximum marks must be set and positive.',
            ];

            return;
        }

        if ($examSubject->getPassingMarks() === null || $examSubject->getPassingMarks() < 0) {
            $errors[] = [
                'examSubjectId' => (string) $examSubject->getId(),
                'subject' => $examSubject->getSubject()->getName(),
                'reason' => ReadinessReason::InvalidConfiguration->value,
                'message' => 'Passing marks must be set.',
            ];
        }
    }

    private function mapExamSubjectToResponse(ExamSubject $examSubject): ExamSubjectResponseDTO
    {
        $subject = $examSubject->getSubject();
        $section = $examSubject->getSection();
        $questionPaper = $examSubject->getQuestionPaper();

        $questionPaperSummary = $this->buildQuestionPaperSummary($questionPaper);

        $examSessionSummary = null;
        $hasSession = false;
        $session = null;
        if ($examSubject->getQuestionPaper() instanceof \App\Entity\QuestionPaper
            && $examSubject->getStatus() !== \App\Enum\ExaminationStatus::Draft) {
            $session = $this->examSessionRepository->findByExamSubject(
                $examSubject->getId(),
                $examSubject->getExamination()->getInstitute()->getId()
            );

            if ($session instanceof \App\Entity\ExamSession) {
                $hasSession = true;
                $examSessionSummary = new \App\DTO\ExaminationModule\ExamSessionSummary(
                    id: $session->getId()->toRfc4122(),
                    status: $session->getStatus()->value,
                    availableFrom: $session->getAvailableFrom()->format(DateTimeInterface::ATOM),
                    availableTo: $session->getAvailableTo()->format(DateTimeInterface::ATOM),
                );
            }
        }

        $readiness = $this->checkQuestionPaperReadiness($examSubject);

        $readinessStatus = $readiness['status'];
        $readinessReason = $readiness['reason'];
        $readinessMessage = $readiness['message'];

        return new ExamSubjectResponseDTO(
            id: (string) $examSubject->getId(),
            examinationId: (string) $examSubject->getExamination()->getId(),
            subjectId: (string) $subject->getId(),
            subjectName: $subject->getName(),
            subjectCode: $subject->getCode(),
            sectionId: $section?->getId()?->toRfc4122(),
            sectionName: $section?->getName() ?? 'ALL SECTION',
            sectionCode: $section?->getCode(),
            examDate: $examSubject->getExamDate()?->format('Y-m-d'),
            startTime: $examSubject->getStartTime()?->format('H:i'),
            endTime: $examSubject->getEndTime()?->format('H:i'),
            duration: $examSubject->getDuration(),
            maxMarks: $examSubject->getMaxMarks(),
            passingMarks: $examSubject->getPassingMarks(),
            status: $examSubject->getStatus()->value,
            remarks: $examSubject->getRemarks(),
            questionPaper: $questionPaperSummary,
            examSession: $examSessionSummary,
            readinessStatus: $readinessStatus,
            readinessReason: $readinessReason,
            readinessMessage: $readinessMessage,
            hasSession: $hasSession,
            isValidated: $examSubject->isValidated(),
            createdAt: $examSubject->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $examSubject->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }

    private function buildQuestionPaperSummary(?QuestionPaper $questionPaper): ?QuestionPaperSummary
    {
        if (!$questionPaper instanceof QuestionPaper) {
            return null;
        }

        $questionCount = $questionPaper->getQuestions()->count();
        $createdBy = $questionPaper->getCreatedBy();
        $createdByName = $createdBy !== null ? $this->userRepository->find($createdBy) : null;

        return new QuestionPaperSummary(
            id: $questionPaper->getId()->toRfc4122(),
            title: $questionPaper->getTitle(),
            subjectId: $questionPaper->getSubject()->getId()->toRfc4122(),
            subjectName: $questionPaper->getSubject()->getName(),
            totalQuestions: $questionCount,
            totalMarks: $questionPaper->getMaximumMarks(),
            status: $questionPaper->getStatus()->value,
            createdByName: $createdByName?->getFullName(),
            createdAt: $questionPaper->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $questionPaper->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }

    private function calculateDurationInMinutes(\DateTimeImmutable $startTime, \DateTimeImmutable $endTime): int
    {
        $startMinutes = (int) $startTime->format('H') * 60 + (int) $startTime->format('i');
        $endMinutes = (int) $endTime->format('H') * 60 + (int) $endTime->format('i');

        return $endMinutes - $startMinutes;
    }

    public function syncExamSubjectStatuses(\DateTimeImmutable $now): array
    {
        $started = 0;
        $completed = 0;
        $utcTimezone = new \DateTimeZone('UTC');

        $scheduledSubjects = $this->examSubjectRepository->findScheduledSubjectsWithTimes();
        foreach ($scheduledSubjects as $examSubject) {
            if ($examSubject->getStatus() !== ExaminationStatus::Scheduled) {
                continue;
            }

            $institute = $examSubject->getExamination()->getInstitute();
            $instituteTimezone = new \DateTimeZone($institute->getTimezone()?->value ?? 'UTC');
            $startDateTime = $this->buildSubjectStartDateTime($examSubject, $instituteTimezone);

            if ($startDateTime !== null && $startDateTime->setTimezone($utcTimezone) <= $now) {
                $examSubject->setStatus(ExaminationStatus::Ongoing);
                $started++;
            }
        }

        $ongoingSubjects = $this->examSubjectRepository->findOngoingSubjectsWithTimes();
        foreach ($ongoingSubjects as $examSubject) {
            if ($examSubject->getStatus() !== ExaminationStatus::Ongoing) {
                continue;
            }

            $institute = $examSubject->getExamination()->getInstitute();
            $instituteTimezone = new \DateTimeZone($institute->getTimezone()?->value ?? 'UTC');
            $endDateTime = $this->buildSubjectEndDateTime($examSubject, $instituteTimezone);

            if ($endDateTime !== null && $endDateTime->setTimezone($utcTimezone) <= $now) {
                $examSubject->setStatus(ExaminationStatus::Completed);
                $completed++;
            }
        }

        $this->entityManager->flush();

        return [
            'started' => $started,
            'completed' => $completed,
        ];
    }

    private function buildSubjectStartDateTime(ExamSubject $examSubject, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        $date = $examSubject->getExamDate();
        $startTime = $examSubject->getStartTime();

        if ($date === null || $startTime === null) {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            $date->format('Y-m-d') . ' ' . $startTime->format('H:i'),
            $timezone
        );

        if (!$dt) {
            return null;
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'));
    }

    private function buildSubjectEndDateTime(ExamSubject $examSubject, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        $date = $examSubject->getExamDate();
        $endTime = $examSubject->getEndTime();

        if ($date === null || $endTime === null) {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            $date->format('Y-m-d') . ' ' . $endTime->format('H:i'),
            $timezone
        );

        if (!$dt) {
            return null;
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'));
    }
}
