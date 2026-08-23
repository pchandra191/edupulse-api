<?php
declare(strict_types=1);

namespace App\Service\ExamAttemptModule;

use App\DTO\ExamAttemptModule\CreateExamAttemptRequestDTO;
use App\DTO\ExamAttemptModule\ExamAttemptFilterRequest;
use App\DTO\ExamAttemptModule\ExamAttemptResponseDTO;
use App\DTO\Response\ExamAttemptListResponse;
use App\Entity\ExamAttempt;
use App\Entity\ExamSession;
use App\Entity\Section;
use App\Entity\StudentEnrollment;
use App\Entity\User;
use App\Enum\ExamAttemptStatus;
use App\Enum\ExamSessionStatus;
use App\Exception\AuthenticationException;
use App\Repository\ExamAttemptRepository;
use App\Repository\ExamSessionRepository;
use App\Repository\EnrollmentSectionRepository;
use App\Repository\StudentEnrollmentRepository;
use DateTimeInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\Registry as WorkflowRegistry;

readonly class ExamAttemptService
{
    public function __construct(
        private Security $security,
        private ExamAttemptRepository $examAttemptRepository,
        private ExamSessionRepository $examSessionRepository,
        private StudentEnrollmentRepository $studentEnrollmentRepository,
        private EnrollmentSectionRepository $enrollmentSectionRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private WorkflowRegistry $workflowRegistry,
    ) {
    }

    public function startAttempt(Uuid $examSessionId): ExamAttemptResponseDTO
    {
        $examSession = $this->examSessionRepository->find($examSessionId);

        if (!$examSession instanceof ExamSession) {
            throw new AuthenticationException('Exam session not found.');
        }

        $now = new \DateTimeImmutable();

        if (!$examSession->isPublished() && !$examSession->isActive()) {
            throw new \App\Common\Exception\BusinessException('This exam session is not currently available.');
        }

        if ($examSession->getAvailableTo() <= $examSession->getAvailableFrom()) {
            throw new \App\Common\Exception\BusinessException('This exam session has invalid availability dates.');
        }

        if ($now < $examSession->getAvailableFrom()) {
            throw new \App\Common\Exception\BusinessException('This exam session is not yet available.');
        }

        if ($now > $examSession->getAvailableTo()) {
            throw new \App\Common\Exception\BusinessException('This exam session has expired.');
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('User not authenticated.');
        }

        if (!$this->isStudentEnrolled($user, $examSession)) {
            throw new \App\Common\Exception\BusinessException('You are not eligible for this exam session.');
        }

        try {
            $this->entityManager->beginTransaction();

            $lockedExamSession = $this->entityManager->find(
                ExamSession::class,
                $examSessionId,
                LockMode::PESSIMISTIC_WRITE
            );

            $existingActiveAttempt = $this->examAttemptRepository->findActiveByExamSessionAndStudent(
                $examSessionId,
                $user->getId()
            );

            if ($existingActiveAttempt instanceof ExamAttempt) {
                $this->entityManager->commit();

                return $this->mapToResponse($existingActiveAttempt);
            }

            $attempt = new ExamAttempt();
            $attempt->setExamSession($lockedExamSession);
            $attempt->setStudent($user);
            $attempt->setStartedAt($now);

            $this->entityManager->persist($attempt);
            $this->entityManager->flush();

            $workflow = $this->workflowRegistry->get($attempt);
            $workflow->apply($attempt, 'start');

            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($attempt);
        } catch (\InvalidArgumentException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw $e;
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to start exam attempt.', [
                'exam_session_id' => (string) $examSessionId,
                'student_id' => (string) $user->getId(),
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to start exam attempt. Please try again.', previous: $e);
        }
    }

    public function submitAttempt(Uuid $id): ExamAttemptResponseDTO
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('User not authenticated.');
        }

        $attempt = $this->examAttemptRepository->find($id);

        if (!$attempt instanceof ExamAttempt) {
            throw new AuthenticationException('Exam attempt not found.');
        }

        if (!$attempt->getStudent()->getId()->equals($user->getId())) {
            throw new \App\Common\Exception\BusinessException('You are not authorized to submit this exam attempt.');
        }

        if (!$attempt->isInProgress()) {
            throw new \App\Common\Exception\BusinessException('Only in-progress exam attempts can be submitted.');
        }

        $workflow = $this->workflowRegistry->get($attempt);

        if (!$workflow->can($attempt, 'submit')) {
            throw new \App\Common\Exception\BusinessException(
                sprintf('Cannot submit exam attempt from %s.', $attempt->getStatus()->value)
            );
        }

        try {
            $this->entityManager->beginTransaction();

            $attempt->setSubmittedAt(new \DateTimeImmutable());
            $workflow->apply($attempt, 'submit');

            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($attempt);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to submit exam attempt.', [
                'exam_attempt_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to submit exam attempt. Please try again.', previous: $e);
        }
    }

    public function getAttempt(Uuid $id, ?Uuid $instituteId): ExamAttemptResponseDTO
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('User not authenticated.');
        }

        $attempt = $this->examAttemptRepository->findById($id, $instituteId);

        if (!$attempt instanceof ExamAttempt) {
            throw new AuthenticationException('Exam attempt not found.');
        }

        if (!$attempt->getStudent()->getId()->equals($user->getId())) {
            throw new \App\Common\Exception\BusinessException('You are not authorized to view this exam attempt.');
        }

        return $this->mapToResponse($attempt);
    }

    public function getMyAttempts(?Uuid $instituteId, ExamAttemptFilterRequest $filter): ExamAttemptListResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('User not authenticated.');
        }

        $result = $this->examAttemptRepository->findPaginatedByStudent(
            $user->getId(),
            $instituteId,
            $filter->search,
            $filter->status,
            $filter->page,
            $filter->limit,
            $filter->sortBy,
            $filter->sortOrder,
        );

        $items = array_map(
            fn (ExamAttempt $attempt) => $this->mapToResponse($attempt),
            $result['items'],
        );

        return new ExamAttemptListResponse(
            items: $items,
            pagination: $result['pagination'],
        );
    }

    private function isStudentEnrolled(User $student, ExamSession $examSession): bool
    {
        if (null === $student->getInstitute()) {
            return false;
        }

        if (!$student->getInstitute()->getId()->equals($examSession->getInstitute()->getId())) {
            return false;
        }

        if ($examSession->getAcademicYear() === null || $examSession->getAcademicClass() === null) {
            return false;
        }

        $enrollment = $this->studentEnrollmentRepository->createQueryBuilder('se')
            ->where('se.student = :student')
            ->andWhere('se.academicYear = :academicYear')
            ->andWhere('se.academicClass = :academicClass')
            ->andWhere('se.status = :status')
            ->setParameter('student', $student->getId())
            ->setParameter('academicYear', $examSession->getAcademicYear()->getId())
            ->setParameter('academicClass', $examSession->getAcademicClass()->getId())
            ->setParameter('status', 'ACTIVE')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$enrollment instanceof StudentEnrollment) {
            return false;
        }

        $studentSections = $this->enrollmentSectionRepository->findByStudentEnrollment($enrollment);

        $examSessionSectionIds = [];
        foreach ($examSession->getSections() as $section) {
            $examSessionSectionIds[] = $section->getId()->toRfc4122();
        }

        if (empty($examSessionSectionIds)) {
            return false;
        }

        foreach ($studentSections as $studentSection) {
            if (in_array($studentSection->getSection()->getId()->toRfc4122(), $examSessionSectionIds, true)) {
                return true;
            }
        }

        return false;
    }

    private function mapToResponse(ExamAttempt $attempt): ExamAttemptResponseDTO
    {
        $examSession = $attempt->getExamSession();
        $student = $attempt->getStudent();

        return new ExamAttemptResponseDTO(
            id: $attempt->getId()->toRfc4122(),
            examSessionId: $examSession->getId()->toRfc4122(),
            examSessionTitle: $examSession->getTitle(),
            studentId: $student->getId()->toRfc4122(),
            studentName: $student->getFullName(),
            status: $attempt->getStatus()->value,
            startedAt: $attempt->getStartedAt()?->format(DateTimeInterface::ATOM),
            submittedAt: $attempt->getSubmittedAt()?->format(DateTimeInterface::ATOM),
            autoSubmittedAt: $attempt->getAutoSubmittedAt()?->format(DateTimeInterface::ATOM),
            createdAt: $attempt->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $attempt->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }
}
