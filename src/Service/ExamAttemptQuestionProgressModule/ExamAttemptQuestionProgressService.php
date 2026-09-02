<?php
declare(strict_types=1);

namespace App\Service\ExamAttemptQuestionProgressModule;

use App\DTO\ExamAttemptQuestionProgressModule\QuestionProgressListResponseDTO;
use App\DTO\ExamAttemptQuestionProgressModule\QuestionProgressResponseDTO;
use App\DTO\ExamAttemptQuestionProgressModule\SaveQuestionProgressRequestDTO;
use App\Entity\ExamAttempt;
use App\Entity\ExamAttemptQuestionProgress;
use App\Entity\ExamAnswer;
use App\Entity\Question;
use App\Enum\ExamQuestionProgressStatus;
use App\Exception\AuthenticationException;
use App\Repository\ExamAnswerRepository;
use App\Repository\ExamAttemptQuestionProgressRepository;
use App\Repository\ExamAttemptRepository;
use App\Repository\QuestionRepository;
use DateTimeInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\Registry as WorkflowRegistry;

readonly class ExamAttemptQuestionProgressService
{
    public function __construct(
        private Security $security,
        private ExamAttemptRepository $examAttemptRepository,
        private ExamAttemptQuestionProgressRepository $progressRepository,
        private ExamAnswerRepository $examAnswerRepository,
        private QuestionRepository $questionRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private WorkflowRegistry $workflowRegistry,
        private ClockInterface $clock,
    ) {
    }

    public function recordProgress(Uuid $attemptId, SaveQuestionProgressRequestDTO $request): QuestionProgressListResponseDTO
    {
        $user = $this->security->getUser();

        if (!$user instanceof \App\Entity\User) {
            throw new AuthenticationException('User not authenticated.');
        }

        $attempt = $this->examAttemptRepository->find($attemptId);

        if (!$attempt instanceof ExamAttempt) {
            throw new AuthenticationException('Exam attempt not found.');
        }

        if (!$attempt->getStudent()->getId()->equals($user->getId())) {
            throw new AuthenticationException('You are not authorized to record progress for this exam attempt.');
        }

        if ($attempt->isOffline()) {
            throw new AuthenticationException('This exam attempt is offline and does not accept online progress updates.');
        }

        if ($attempt->isTerminal()) {
            throw new AuthenticationException('Progress can no longer be updated for this exam attempt.');
        }

        if (!$attempt->isInProgress()) {
            throw new AuthenticationException('Progress can only be recorded for in-progress exam attempts.');
        }

        if ($attempt->getExpiresAt() !== null && $this->clock->now() >= $attempt->getExpiresAt()) {
            throw new AuthenticationException('This exam attempt has expired. Progress can no longer be recorded.');
        }

        $action = $request->action;

        if (in_array($action, ['ENTER', 'LEAVE'], true)) {
            if ($request->questionId === null) {
                throw new AuthenticationException('Question ID is required for ENTER and LEAVE actions.');
            }
        }

        if (in_array($action, ['NEXT', 'PREVIOUS'], true)) {
            if ($request->fromQuestionId === null || $request->toQuestionId === null) {
                throw new AuthenticationException('From and to question IDs are required for NEXT and PREVIOUS actions.');
            }

            if ($request->fromQuestionId === $request->toQuestionId) {
                throw new AuthenticationException('From and to question IDs must be different.');
            }
        }

        $this->entityManager->beginTransaction();

        try {
            $lockedAttempt = $this->entityManager->find(
                ExamAttempt::class,
                $attemptId,
                LockMode::PESSIMISTIC_WRITE
            );

            if (in_array($action, ['NEXT', 'PREVIOUS'], true)) {
                $this->closeQuestionTiming($attemptId, Uuid::fromString($request->fromQuestionId));
                $this->openQuestionTiming($attemptId, Uuid::fromString($request->toQuestionId));
            } elseif ($action === 'ENTER') {
                $targetQuestionId = Uuid::fromString($request->questionId);
                $activeProgress = $this->progressRepository->findActiveQuestion($attemptId);

                if ($activeProgress !== null && $activeProgress->getQuestion()->getId()->equals($targetQuestionId)) {
                    $this->openQuestionTiming($attemptId, $targetQuestionId);
                } else {
                    $this->closeAnyActiveQuestion($attemptId);
                    $this->openQuestionTiming($attemptId, $targetQuestionId);
                }
            } elseif ($action === 'LEAVE') {
                $this->closeQuestionTiming($attemptId, Uuid::fromString($request->questionId));
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->getProgress($attemptId);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to record question progress.', [
                'attempt_id' => (string) $attemptId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function getProgress(Uuid $attemptId): QuestionProgressListResponseDTO
    {
        $user = $this->security->getUser();

        if (!$user instanceof \App\Entity\User) {
            throw new AuthenticationException('User not authenticated.');
        }

        $attempt = $this->examAttemptRepository->find($attemptId);

        if (!$attempt instanceof ExamAttempt) {
            throw new AuthenticationException('Exam attempt not found.');
        }

        if (!$attempt->getStudent()->getId()->equals($user->getId())) {
            throw new AuthenticationException('You are not authorized to view progress for this exam attempt.');
        }

        $progresses = $this->progressRepository->findByAttempt($attemptId);
        $questions = $this->examAttemptRepository->findQuestionsByAttempt($attemptId);

        if (empty($questions)) {
            return new QuestionProgressListResponseDTO(
                attemptId: $attemptId->toRfc4122(),
                items: [],
            );
        }

        $answers = $this->examAnswerRepository->findByAttempt($attempt);
        $answerMap = [];
        foreach ($answers as $answer) {
            $answerMap[$answer->getQuestion()->getId()->toRfc4122()] = $answer;
        }

        $progressMap = [];
        foreach ($progresses as $progress) {
            $progressMap[$progress->getQuestion()->getId()->toRfc4122()] = $progress;
        }

        $items = [];
        foreach ($questions as $question) {
            $questionId = $question->getId()->toRfc4122();

            if (isset($progressMap[$questionId])) {
                $progress = $progressMap[$questionId];
                $items[] = new QuestionProgressResponseDTO(
                    questionId: $questionId,
                    status: $this->computeStatus($progress, $answerMap),
                    totalTimeSeconds: $progress->getTotalTimeSeconds(),
                    visitCount: $progress->getVisitCount(),
                    navigationCount: $progress->getNavigationCount(),
                    firstViewedAt: $progress->getFirstViewedAt()?->format(DateTimeInterface::ATOM),
                    lastViewedAt: $progress->getLastViewedAt()?->format(DateTimeInterface::ATOM),
                    firstAnsweredAt: $progress->getFirstAnsweredAt()?->format(DateTimeInterface::ATOM),
                    lastAnsweredAt: $progress->getLastAnsweredAt()?->format(DateTimeInterface::ATOM),
                    answerChangeCount: $progress->getAnswerChangeCount(),
                );
            } elseif (isset($answerMap[$questionId])) {
                $answer = $answerMap[$questionId];
                $items[] = new QuestionProgressResponseDTO(
                    questionId: $questionId,
                    status: ExamQuestionProgressStatus::Answered,
                    totalTimeSeconds: 0,
                    visitCount: 0,
                    navigationCount: 0,
                    firstViewedAt: null,
                    lastViewedAt: null,
                    firstAnsweredAt: $answer->getCreatedAt()->format(DateTimeInterface::ATOM),
                    lastAnsweredAt: $answer->getUpdatedAt()->format(DateTimeInterface::ATOM),
                    answerChangeCount: 1,
                );
            } else {
                $items[] = new QuestionProgressResponseDTO(
                    questionId: $questionId,
                    status: ExamQuestionProgressStatus::NotAttempted,
                    totalTimeSeconds: 0,
                    visitCount: 0,
                    navigationCount: 0,
                    firstViewedAt: null,
                    lastViewedAt: null,
                    firstAnsweredAt: null,
                    lastAnsweredAt: null,
                    answerChangeCount: 0,
                );
            }
        }

        return new QuestionProgressListResponseDTO(
            attemptId: $attemptId->toRfc4122(),
            items: $items,
        );
    }

    public function updateProgressForAnswer(Uuid $attemptId, Question $question, bool $isNewAnswer, bool $answerChanged): void
    {
        $this->entityManager->beginTransaction();

        try {
            $lockedAttempt = $this->entityManager->find(
                ExamAttempt::class,
                $attemptId,
                LockMode::PESSIMISTIC_WRITE
            );

            $progress = $this->progressRepository->findByAttemptAndQuestion($attemptId, $question->getId());

            if ($progress === null) {
                $progress = new ExamAttemptQuestionProgress();
                $progress->setExamAttempt($lockedAttempt);
                $progress->setQuestion($question);
                $progress->setStatus(ExamQuestionProgressStatus::Answered);
                $progress->setFirstAnsweredAt($this->clock->now());
                $progress->setLastAnsweredAt($this->clock->now());
                $progress->setAnswerChangeCount($isNewAnswer ? 1 : 0);

                $this->entityManager->persist($progress);
            } else {
                $progress->setStatus(ExamQuestionProgressStatus::Answered);

                if ($progress->getFirstAnsweredAt() === null) {
                    $progress->setFirstAnsweredAt($this->clock->now());
                }

                $progress->setLastAnsweredAt($this->clock->now());

                if ($answerChanged) {
                    $progress->setAnswerChangeCount($progress->getAnswerChangeCount() + 1);
                }
            }

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to update progress for answer.', [
                'attempt_id' => (string) $attemptId,
                'question_id' => (string) $question->getId(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function closeActiveQuestion(Uuid $attemptId): void
    {
        $this->closeAnyActiveQuestion($attemptId);
    }

    private function closeQuestionTiming(Uuid $attemptId, Uuid $questionId): void
    {
        $progress = $this->progressRepository->findByAttemptAndQuestion($attemptId, $questionId);

        if ($progress === null) {
            return;
        }

        if ($progress->getLastViewedAt() === null) {
            return;
        }

        $now = $this->clock->now();
        $elapsed = $now->getTimestamp() - $progress->getLastViewedAt()->getTimestamp();

        if ($elapsed > 0) {
            $progress->setTotalTimeSeconds($progress->getTotalTimeSeconds() + $elapsed);
        }

        $progress->setLastViewedAt(null);
        $progress->setNavigationCount($progress->getNavigationCount() + 1);

        $this->entityManager->persist($progress);
    }

    private function openQuestionTiming(Uuid $attemptId, Uuid $questionId): void
    {
        $question = $this->questionRepository->find($questionId);

        if (!$question instanceof Question) {
            throw new AuthenticationException('Question not found.');
        }

        $examSession = $this->examAttemptRepository->find($attemptId)?->getExamSession();
        $questionPaper = $question->getQuestionPaper();

        if ($examSession === null || !$questionPaper->getId()->equals($examSession->getQuestionPaper()->getId())) {
            throw new AuthenticationException('Question does not belong to this exam attempt.');
        }

        $progress = $this->progressRepository->findByAttemptAndQuestion($attemptId, $questionId);

        if ($progress === null) {
            $progress = new ExamAttemptQuestionProgress();
            $lockedAttempt = $this->entityManager->find(ExamAttempt::class, $attemptId, LockMode::PESSIMISTIC_WRITE);
            $progress->setExamAttempt($lockedAttempt);
            $progress->setQuestion($question);
            $progress->setVisitCount(1);
            $progress->setNavigationCount(1);
            $progress->setFirstViewedAt($this->clock->now());
            $progress->setLastViewedAt($this->clock->now());

            $this->entityManager->persist($progress);
        } else {
            if ($progress->getLastViewedAt() !== null) {
                $now = $this->clock->now();
                $elapsed = $now->getTimestamp() - $progress->getLastViewedAt()->getTimestamp();

                if ($elapsed > 0) {
                    $progress->setTotalTimeSeconds($progress->getTotalTimeSeconds() + $elapsed);
                }

                $progress->setLastViewedAt($now);
            } else {
                $progress->setVisitCount($progress->getVisitCount() + 1);
                $progress->setNavigationCount($progress->getNavigationCount() + 1);
                $progress->setLastViewedAt($this->clock->now());
            }

            $this->entityManager->persist($progress);
        }
    }

    private function closeAnyActiveQuestion(Uuid $attemptId): void
    {
        $activeProgress = $this->progressRepository->findActiveQuestion($attemptId);

        if ($activeProgress === null) {
            return;
        }

        $this->closeQuestionTiming($attemptId, $activeProgress->getQuestion()->getId());
    }

    private function computeStatus(ExamAttemptQuestionProgress $progress, array $answerMap): ExamQuestionProgressStatus
    {
        $questionId = $progress->getQuestion()->getId()->toRfc4122();

        if (isset($answerMap[$questionId])) {
            return ExamQuestionProgressStatus::Answered;
        }

        if ($progress->getVisitCount() > 0 || $progress->getFirstViewedAt() !== null) {
            return ExamQuestionProgressStatus::AttemptedUnanswered;
        }

        return ExamQuestionProgressStatus::NotAttempted;
    }
}
