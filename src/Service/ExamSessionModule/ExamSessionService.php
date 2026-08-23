<?php
declare(strict_types=1);

namespace App\Service\ExamSessionModule;

use App\DTO\ExamSessionModule\CreateExamSessionRequestDTO;
use App\DTO\ExamSessionModule\ExamSessionFilterRequest;
use App\DTO\ExamSessionModule\ExamSessionResponseDTO;
use App\DTO\ExamSessionModule\UpdateExamSessionRequestDTO;
use App\DTO\Response\ExamSessionListResponse;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\ExamSession;
use App\Entity\Institute;
use App\Entity\QuestionPaper;
use App\Entity\Section;
use App\Entity\User;
use App\Enum\ExamSessionStatus;
use App\Enum\Timezone;
use App\Exception\AuthenticationException;
use App\Repository\AcademicClassRepository;
use App\Repository\AcademicYearRepository;
use App\Repository\ExamSessionRepository;
use App\Repository\InstituteRepository;
use App\Repository\SectionRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\Registry as WorkflowRegistry;

readonly class ExamSessionService
{
    public function __construct(
        private Security $security,
        private ExamSessionRepository $examSessionRepository,
        private InstituteRepository $instituteRepository,
        private AcademicYearRepository $academicYearRepository,
        private AcademicClassRepository $academicClassRepository,
        private SectionRepository $sectionRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private WorkflowRegistry $workflowRegistry,
    ) {
    }

    public function getExamSessions(?Uuid $instituteId, ExamSessionFilterRequest $filter): ExamSessionListResponse
    {
        $result = $this->examSessionRepository->findPaginated(
            $instituteId,
            $filter->search,
            $filter->questionPaperId ? Uuid::fromString($filter->questionPaperId) : null,
            $filter->examinationId ? Uuid::fromString($filter->examinationId) : null,
            $filter->status,
            $filter->page,
            $filter->limit,
            $filter->sortBy,
            $filter->sortOrder,
        );

        $items = array_map(
            fn (ExamSession $examSession) => $this->mapToResponse($examSession),
            $result['items'],
        );

        return new ExamSessionListResponse(
            items: $items,
            pagination: $result['pagination'],
        );
    }

    public function getExamSession(Uuid $id, ?Uuid $instituteId): ExamSessionResponseDTO
    {
        $examSession = $this->examSessionRepository->findById($id, $instituteId);

        if (!$examSession instanceof ExamSession) {
            throw new AuthenticationException('Exam session not found.');
        }

        return $this->mapToResponse($examSession);
    }

    public function createExamSession(Uuid $instituteId, CreateExamSessionRequestDTO $request): ExamSessionResponseDTO
    {
        $institute = $this->instituteRepository->find($instituteId);

        if (!$institute instanceof Institute) {
            throw new AuthenticationException('Institute not found.');
        }

        $questionPaper = $this->entityManager->getRepository(QuestionPaper::class)->find($request->questionPaperId);

        if (!$questionPaper instanceof QuestionPaper) {
            throw new \InvalidArgumentException('Question paper not found.');
        }

        if (!$questionPaper->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Question paper does not belong to the authenticated institute.');
        }

        if (!$questionPaper->isFinalized()) {
            throw new \InvalidArgumentException('Only finalized question papers can be used for exam sessions.');
        }

        $examination = null;
        if ($request->examinationId !== null && $request->examinationId !== '') {
            $examination = $this->entityManager->getRepository(\App\Entity\Examination::class)->find($request->examinationId);

            if (!$examination instanceof \App\Entity\Examination) {
                throw new \InvalidArgumentException('Examination not found.');
            }

            if (!$examination->getInstitute()->getId()->equals($instituteId)) {
                throw new \InvalidArgumentException('Examination does not belong to the authenticated institute.');
            }
        }

        $examSubject = null;
        if ($request->examSubjectId !== null && $request->examSubjectId !== '') {
            $examSubject = $this->entityManager->getRepository(\App\Entity\ExamSubject::class)->find($request->examSubjectId);

            if (!$examSubject instanceof \App\Entity\ExamSubject) {
                throw new \InvalidArgumentException('Exam subject not found.');
            }
        }

        $availableFrom = \DateTimeImmutable::createFromFormat('d-m-Y H:i', $request->availableFrom, new \DateTimeZone($institute->getTimezone()?->value ?? 'UTC'));
        $availableTo = \DateTimeImmutable::createFromFormat('d-m-Y H:i', $request->availableTo, new \DateTimeZone($institute->getTimezone()?->value ?? 'UTC'));

        if (!$availableFrom || !$availableTo) {
            throw new \InvalidArgumentException('Invalid date format. Expected DD-MM-YYYY HH:II.');
        }

        $availableFrom = $availableFrom->setTimezone(new \DateTimeZone('UTC'));
        $availableTo = $availableTo->setTimezone(new \DateTimeZone('UTC'));

        if ($availableTo <= $availableFrom) {
            throw new \InvalidArgumentException('availableTo must be after availableFrom.');
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('User not authenticated.');
        }

        $totalMarks = $request->totalMarks > 0 ? $request->totalMarks : $questionPaper->getMaximumMarks();

        $academicYear = $this->academicYearRepository->find($request->academicYearId);

        if (!$academicYear instanceof AcademicYear) {
            throw new \InvalidArgumentException('Academic year not found.');
        }

        if (!$academicYear->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Academic year does not belong to the authenticated institute.');
        }

        $academicClass = $this->academicClassRepository->find($request->academicClassId);

        if (!$academicClass instanceof AcademicClass) {
            throw new \InvalidArgumentException('Academic class not found.');
        }

        if (!$academicClass->getAcademicYear()->getId()->equals($academicYear->getId())) {
            throw new \InvalidArgumentException('Academic class does not belong to the selected academic year.');
        }

        if (!$academicClass->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Academic class does not belong to the authenticated institute.');
        }

        if (empty($request->sectionIds)) {
            throw new \InvalidArgumentException('At least one section must be selected.');
        }

        $validSectionIds = [];
        foreach ($request->sectionIds as $sectionId) {
            $section = $this->sectionRepository->find($sectionId);

            if (!$section instanceof Section) {
                throw new \InvalidArgumentException('Section not found: ' . $sectionId);
            }

            if (!$section->getAcademicClass()->getId()->equals($academicClass->getId())) {
                throw new \InvalidArgumentException('Section does not belong to the selected academic class.');
            }

            $validSectionIds[] = $section->getId()->toRfc4122();
        }

        try {
            $this->entityManager->beginTransaction();

            $examSession = new ExamSession();
            $examSession->setInstitute($institute);
            $examSession->setAcademicYear($academicYear);
            $examSession->setAcademicClass($academicClass);
            $examSession->setQuestionPaper($questionPaper);
            $examSession->setCreatedBy($user);
            $examSession->setTitle($request->title);
            $examSession->setAvailableFrom($availableFrom);
            $examSession->setAvailableTo($availableTo);
            $examSession->setShuffleQuestions($request->shuffleQuestions);
            $examSession->setShowResultsMode($request->showResultsMode);
            $examSession->setAccessMode($request->accessMode);
            $examSession->setAllowReview($request->allowReview);
            $examSession->setTotalMarks($totalMarks);
            $examSession->setStatus(ExamSessionStatus::from($request->status));

            foreach ($validSectionIds as $sectionId) {
                $section = $this->sectionRepository->find(Uuid::fromString($sectionId));
                if ($section instanceof Section) {
                    $examSession->addSection($section);
                }
            }

            if ($examination !== null) {
                $examSession->setExamination($examination);
            }

            if ($examSubject !== null) {
                $examSession->setExamSubject($examSubject);
            }

            if ($request->durationOverride !== null) {
                $examSession->setDurationOverride($request->durationOverride);
            }

            $this->entityManager->persist($examSession);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($examSession);
        } catch (\InvalidArgumentException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw $e;
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to create exam session.', [
                'institute_id' => (string) $instituteId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to create exam session. Please try again.', previous: $e);
        }
    }

    public function updateExamSession(Uuid $id, ?Uuid $instituteId, UpdateExamSessionRequestDTO $request): ExamSessionResponseDTO
    {
        $examSession = $this->examSessionRepository->findById($id, $instituteId);

        if (!$examSession instanceof ExamSession) {
            throw new AuthenticationException('Exam session not found.');
        }

        if (!$examSession->isDraft()) {
            throw new \App\Common\Exception\BusinessException('Only draft exam sessions can be modified through the update endpoint.');
        }

        try {
            $this->entityManager->beginTransaction();
            $this->updateFields($examSession, $request);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($examSession);
        } catch (\InvalidArgumentException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw $e;
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to update exam session.', [
                'exam_session_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to update exam session. Please try again.', previous: $e);
        }
    }

    public function deleteExamSession(Uuid $id, ?Uuid $instituteId): void
    {
        $examSession = $this->examSessionRepository->findById($id, $instituteId);

        if (!$examSession instanceof ExamSession) {
            throw new AuthenticationException('Exam session not found.');
        }

        if ($examSession->isArchived()) {
            throw new \App\Common\Exception\BusinessException('Archived exam sessions cannot be deleted.');
        }

        try {
            $examSession->setStatus(ExamSessionStatus::Archived);

            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete exam session.', [
                'exam_session_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to delete exam session. Please try again.', previous: $e);
        }
    }

    public function transitionExamSession(Uuid $id, ?Uuid $instituteId, string $transition): ExamSessionResponseDTO
    {
        $examSession = $this->examSessionRepository->findById($id, $instituteId);

        if (!$examSession instanceof ExamSession) {
            throw new AuthenticationException('Exam session not found.');
        }

        if (in_array($transition, ['schedule', 'publish'], true)) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            if ($examSession->getAvailableTo() <= $examSession->getAvailableFrom()) {
                throw new \InvalidArgumentException('availableTo must be after availableFrom.');
            }

            if ($examSession->getAvailableTo() <= $now) {
                throw new \App\Common\Exception\BusinessException('Cannot schedule or publish an exam session that has already expired.');
            }
        }

        $workflow = $this->workflowRegistry->get($examSession);

        if (!$workflow->can($examSession, $transition)) {
            throw new \App\Common\Exception\BusinessException(
                sprintf('Cannot transition exam session from %s using "%s".', $examSession->getStatus()->value, $transition)
            );
        }

        try {
            $workflow->apply($examSession, $transition);

            if ($transition === 'publish') {
                $examSession->setPublishedAt(new \DateTimeImmutable());
            }

            $this->entityManager->flush();

            return $this->mapToResponse($examSession);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to transition exam session.', [
                'exam_session_id' => (string) $id,
                'transition' => $transition,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to transition exam session. Please try again.', previous: $e);
        }
    }

    private function updateFields(ExamSession $examSession, UpdateExamSessionRequestDTO $request): void
    {
        if ($request->title !== null && $request->title !== $examSession->getTitle()) {
            $examSession->setTitle($request->title);
        }

        if ($request->availableFrom !== null) {
            $instituteTimezone = $examSession->getInstitute()->getTimezone()?->value ?? 'UTC';
            $availableFrom = \DateTimeImmutable::createFromFormat('d-m-Y H:i', $request->availableFrom, new \DateTimeZone($instituteTimezone));

            if (!$availableFrom) {
                throw new \InvalidArgumentException('Invalid availableFrom format. Expected DD-MM-YYYY HH:II.');
            }

            $examSession->setAvailableFrom($availableFrom->setTimezone(new \DateTimeZone('UTC')));
        }

        if ($request->availableTo !== null) {
            $instituteTimezone = $examSession->getInstitute()->getTimezone()?->value ?? 'UTC';
            $availableTo = \DateTimeImmutable::createFromFormat('d-m-Y H:i', $request->availableTo, new \DateTimeZone($instituteTimezone));

            if (!$availableTo) {
                throw new \InvalidArgumentException('Invalid availableTo format. Expected DD-MM-YYYY HH:II.');
            }

            if ($availableTo->setTimezone(new \DateTimeZone('UTC')) <= $examSession->getAvailableFrom()) {
                throw new \InvalidArgumentException('availableTo must be after availableFrom.');
            }

            $examSession->setAvailableTo($availableTo->setTimezone(new \DateTimeZone('UTC')));
        }

        if ($request->durationOverride !== null) {
            $examSession->setDurationOverride($request->durationOverride);
        }

        if ($request->shuffleQuestions !== null) {
            $examSession->setShuffleQuestions($request->shuffleQuestions);
        }

        if ($request->showResultsMode !== null) {
            $examSession->setShowResultsMode($request->showResultsMode);
        }

        if ($request->accessMode !== null) {
            $examSession->setAccessMode($request->accessMode);
        }

        if ($request->allowReview !== null) {
            $examSession->setAllowReview($request->allowReview);
        }

        if ($request->totalMarks !== null) {
            $examSession->setTotalMarks($request->totalMarks);
        }

        if ($request->academicYearId !== null) {
            $academicYear = $this->academicYearRepository->find($request->academicYearId);

            if (!$academicYear instanceof AcademicYear) {
                throw new \InvalidArgumentException('Academic year not found.');
            }

            if (!$academicYear->getInstitute()->getId()->equals($examSession->getInstitute()->getId())) {
                throw new \InvalidArgumentException('Academic year does not belong to the authenticated institute.');
            }

            $examSession->setAcademicYear($academicYear);
        }

        if ($request->academicClassId !== null) {
            $academicClass = $this->academicClassRepository->find($request->academicClassId);

            if (!$academicClass instanceof AcademicClass) {
                throw new \InvalidArgumentException('Academic class not found.');
            }

            if (!$academicClass->getAcademicYear()->getId()->equals($examSession->getAcademicYear()->getId())) {
                throw new \InvalidArgumentException('Academic class does not belong to the selected academic year.');
            }

            if (!$academicClass->getInstitute()->getId()->equals($examSession->getInstitute()->getId())) {
                throw new \InvalidArgumentException('Academic class does not belong to the authenticated institute.');
            }

            $examSession->setAcademicClass($academicClass);
        }

        if ($request->sectionIds !== null) {
            if (empty($request->sectionIds)) {
                throw new \InvalidArgumentException('At least one section must be selected.');
            }

            $validSectionIds = [];
            foreach ($request->sectionIds as $sectionId) {
                $section = $this->sectionRepository->find($sectionId);

                if (!$section instanceof Section) {
                    throw new \InvalidArgumentException('Section not found: ' . $sectionId);
                }

                if (!$section->getAcademicClass()->getId()->equals($examSession->getAcademicClass()->getId())) {
                    throw new \InvalidArgumentException('Section does not belong to the selected academic class.');
                }

                $validSectionIds[] = $section->getId()->toRfc4122();
            }

            $examSession->clearSections();

            foreach ($validSectionIds as $sectionId) {
                $section = $this->sectionRepository->find(Uuid::fromString($sectionId));
                if ($section instanceof Section) {
                    $examSession->addSection($section);
                }
            }
        }

        if ($request->status !== null) {
            $target = ExamSessionStatus::from($request->status);
            $current = $examSession->getStatus();

            if ($current !== $target) {
                $transition = $this->resolveExamSessionTransition($current, $target);

                if ($transition === null || !$this->workflowRegistry->get($examSession)->can($examSession, $transition)) {
                    throw new \App\Common\Exception\BusinessException(
                        sprintf('Invalid exam session status transition from %s to %s.', $current->value, $target->value)
                    );
                }

                $this->workflowRegistry->get($examSession)->apply($examSession, $transition);

                if ($transition === 'publish') {
                    $examSession->setPublishedAt(new \DateTimeImmutable());
                }
            }
        }
    }

    private function resolveExamSessionTransition(ExamSessionStatus $from, ExamSessionStatus $to): ?string
    {
        return match (true) {
            $from === $to => null,
            $from === ExamSessionStatus::Draft && $to === ExamSessionStatus::Scheduled => 'schedule',
            $from === ExamSessionStatus::Scheduled && $to === ExamSessionStatus::Published => 'publish',
            $from === ExamSessionStatus::Published && $to === ExamSessionStatus::Active => 'start',
            $from === ExamSessionStatus::Published && $to === ExamSessionStatus::Draft => 'reopen',
            $from === ExamSessionStatus::Active && $to === ExamSessionStatus::Closed => 'close',
            default => null,
        };
    }

    private function mapToResponse(ExamSession $examSession): ExamSessionResponseDTO
    {
        $institute = $examSession->getInstitute();
        $questionPaper = $examSession->getQuestionPaper();
        $createdBy = $examSession->getCreatedBy();
        $academicYear = $examSession->getAcademicYear();
        $academicClass = $examSession->getAcademicClass();

        $sections = array_map(
            fn (Section $section) => [
                'id' => $section->getId()->toRfc4122(),
                'name' => $section->getName(),
            ],
            $examSession->getSections()->toArray()
        );

        $displayTimezone = new \DateTimeZone($institute->getTimezone()?->value ?? 'UTC');

        return new ExamSessionResponseDTO(
            id: $examSession->getId()->toRfc4122(),
            instituteId: $institute->getId()->toRfc4122(),
            instituteName: $institute->getName(),
            questionPaperId: $questionPaper->getId()->toRfc4122(),
            questionPaperTitle: $questionPaper->getTitle(),
            examinationId: $examSession->getExamination()?->getId()->toRfc4122(),
            examinationName: $examSession->getExamination()?->getName(),
            examSubjectId: $examSession->getExamSubject()?->getId()->toRfc4122(),
            examSubjectName: $examSession->getExamSubject()?->getSubject()->getName(),
            createdById: $createdBy->getId()->toRfc4122(),
            createdByName: $createdBy->getFirstName() . ' ' . $createdBy->getLastName(),
            title: $examSession->getTitle(),
            publishedAt: $examSession->getPublishedAt()?->setTimezone($displayTimezone)->format(DateTimeInterface::ATOM),
            availableFrom: $examSession->getAvailableFrom()->setTimezone($displayTimezone)->format(DateTimeInterface::ATOM),
            availableTo: $examSession->getAvailableTo()->setTimezone($displayTimezone)->format(DateTimeInterface::ATOM),
            durationOverride: $examSession->getDurationOverride(),
            shuffleQuestions: $examSession->isShuffleQuestions(),
            showResultsMode: $examSession->getShowResultsMode(),
            accessMode: $examSession->getAccessMode(),
            allowReview: $examSession->isAllowReview(),
            totalMarks: $examSession->getTotalMarks(),
            status: $examSession->getStatus()->value,
            createdAt: $examSession->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $examSession->getUpdatedAt()?->format(DateTimeInterface::ATOM),
            academicYearId: $academicYear->getId()->toRfc4122(),
            academicYearName: $academicYear->getName(),
            academicClassId: $academicClass->getId()->toRfc4122(),
            academicClassName: $academicClass->getName(),
            sections: $sections,
        );
    }
}
