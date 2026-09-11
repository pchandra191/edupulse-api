<?php
declare(strict_types=1);

namespace App\Service\ExaminationModule;

use App\Entity\ExamAttempt;
use App\Entity\ExamSession;
use App\Entity\ExamSubject;
use App\Entity\QuestionPaper;
use App\Entity\User;
use App\Enum\ExamAttemptStatus;
use App\Enum\ExamSessionStatus;
use App\Enum\ExaminationStatus;
use App\Repository\ExamAttemptRepository;
use App\Repository\ExamSessionRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\Registry as WorkflowRegistry;

readonly class ExamSessionSynchronizer
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExamSessionRepository $examSessionRepository,
        private ExamAttemptRepository $examAttemptRepository,
        private LoggerInterface $logger,
        private Security $security,
        private WorkflowRegistry $workflowRegistry,
    ) {
    }

    public function synchronize(ExamSubject $examSubject): ?ExamSession
    {
        $paper = $examSubject->getQuestionPaper();

        if (!$paper instanceof QuestionPaper) {
            $existing = $this->findExisting($examSubject);

            if ($existing instanceof ExamSession && !$this->hasAttempts($existing)) {
                $existing->setStatus(ExamSessionStatus::Archived);
                $this->entityManager->flush();
            }

            return null;
        }

        if ($examSubject->getStatus() === ExaminationStatus::Draft) {
            $existing = $this->findExisting($examSubject);

            if ($existing instanceof ExamSession && !$this->hasAttempts($existing)) {
                $existing->setStatus(ExamSessionStatus::Draft);
                $this->entityManager->flush();
            }

            return null;
        }

        $existing = $this->findExisting($examSubject);

        if ($existing instanceof ExamSession) {
            $targetStatus = $this->mapStatus($examSubject->getStatus());
            $currentStatus = $existing->getStatus();

            if (in_array($currentStatus, [ExamSessionStatus::Archived, ExamSessionStatus::Cancelled, ExamSessionStatus::Closed], true)) {
                $existing->setStatus(ExamSessionStatus::Draft);
                $currentStatus = ExamSessionStatus::Draft;
                $targetStatus = ExamSessionStatus::Draft;
            }

            if ($currentStatus !== $targetStatus) {
                $workflow = $this->workflowRegistry->get($existing);
                $transition = $this->resolveExamSessionTransition($currentStatus, $targetStatus);

                if ($transition !== null && $workflow->can($existing, $transition)) {
                    $workflow->apply($existing, $transition);

                    if ($transition === 'publish') {
                        $existing->setPublishedAt(new \DateTimeImmutable());
                    }

                    $now = new \DateTimeImmutable();
                    $this->entityManager->flush();

                    if ($transition === 'close') {
                        $this->finalizeInProgressAttempts($existing, $now);
                    }
                }
            }

            $this->updateSessionFromExamSubject($existing, $examSubject, $paper);
            $this->entityManager->flush();

            return $existing;
        }

        $session = $this->createSessionFromExamSubject($examSubject, $paper);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    /**
     * Ensure an ExamSession is PUBLISHED after Validate & Start Exam.
     *
     * @param ExamSession $session
     * @return void
     */
    public function ensurePublished(ExamSession $session): void
    {
        $currentStatus = $session->getStatus();

        if (in_array($currentStatus, [ExamSessionStatus::Published, ExamSessionStatus::Active, ExamSessionStatus::Closed], true)) {
            return;
        }

        $workflow = $this->workflowRegistry->get($session);

        if ($currentStatus === ExamSessionStatus::Draft && $workflow->can($session, 'schedule')) {
            $workflow->apply($session, 'schedule');
            $this->entityManager->flush();
        }

        $currentStatus = $session->getStatus();

        if ($currentStatus === ExamSessionStatus::Scheduled && $workflow->can($session, 'publish')) {
            $workflow->apply($session, 'publish');
            $session->setPublishedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
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

    public function synchronizeAllForExamination(ExaminationStatus $targetStatus = null): int
    {
        $examSubjectRepo = $this->entityManager->getRepository(ExamSubject::class);

        $criteria = ['status' => ExaminationStatus::Scheduled];

        if ($targetStatus !== null) {
            $criteria = ['status' => $targetStatus];
        }

        $qb = $examSubjectRepo->createQueryBuilder('es')
            ->where('es.status IN (:statuses)')
            ->setParameter('statuses', [
                ExaminationStatus::Scheduled->value,
                ExaminationStatus::Ongoing->value,
                ExaminationStatus::Completed->value,
                ExaminationStatus::Cancelled->value,
            ]);

        $examSubjects = $qb->getQuery()->getResult();

        $synced = 0;

        foreach ($examSubjects as $examSubject) {
            if (!$examSubject->getQuestionPaper() instanceof QuestionPaper) {
                continue;
            }

            try {
                $this->synchronize($examSubject);
                $synced++;
            } catch (\Throwable $e) {
                $this->logger->error('ExamSessionSynchronizer: Failed to sync exam subject {id}.', [
                    'exam_subject_id' => (string) $examSubject->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $synced;
    }

    private function findExisting(ExamSubject $examSubject): ?ExamSession
    {
        return $this->examSessionRepository->findByExamSubject(
            $examSubject->getId(),
            $examSubject->getExamination()->getInstitute()->getId()
        );
    }

    private function hasAttempts(ExamSession $session): bool
    {
        return count($session->getExamAttempts()) > 0;
    }

    private function createSessionFromExamSubject(ExamSubject $examSubject, QuestionPaper $paper): ExamSession
    {
        $examination = $examSubject->getExamination();
        $institute = $examination->getInstitute();
        $timezone = new \DateTimeZone($institute->getTimezone()?->value ?? 'UTC');

        $session = new ExamSession();
        $session->setInstitute($institute);
        $session->setAcademicYear($examination->getAcademicYear());
        $session->setAcademicClass($examination->getAcademicClass());
        $session->setQuestionPaper($paper);
        $session->setExamination($examination);
        $session->setExamSubject($examSubject);

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $session->setCreatedBy($user);
        }

        $session->setTitle($examSubject->getSubject()->getName() . ' - ' . $examination->getName());
        $session->setStatus($this->mapStatus($examSubject->getStatus()));

        $availableFrom = $this->buildAvailableFrom($examSubject, $timezone);
        $availableTo = $this->buildAvailableTo($examSubject, $timezone);

        if ($availableFrom !== null) {
            $session->setAvailableFrom($availableFrom);
        } else {
            $session->setAvailableFrom(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        }

        if ($availableTo !== null) {
            $session->setAvailableTo($availableTo);
        } else {
            $session->setAvailableTo(new \DateTimeImmutable('now +1 hour', new \DateTimeZone('UTC')));
        }

        if ($examSubject->getDuration() !== null) {
            $session->setDurationOverride($examSubject->getDuration());
        }

        if ($examSubject->getMaxMarks() !== null) {
            $session->setTotalMarks($examSubject->getMaxMarks());
        }

        if ($examSubject->getSection() !== null) {
            $session->addSection($examSubject->getSection());
        }

        $session->setShowResultsMode('IMMEDIATE');
        $session->setAccessMode('OPEN');

        return $session;
    }

    private function updateSessionFromExamSubject(ExamSession $session, ExamSubject $examSubject, QuestionPaper $paper): void
    {
        $session->setQuestionPaper($paper);

        if ($examSubject->getDuration() !== null) {
            $session->setDurationOverride($examSubject->getDuration());
        }

        if ($examSubject->getMaxMarks() !== null) {
            $session->setTotalMarks($examSubject->getMaxMarks());
        }

        if ($examSubject->getSection() !== null) {
            $currentSectionIds = [];
            foreach ($session->getSections() as $section) {
                $currentSectionIds[] = $section->getId()->toRfc4122();
            }

            $newSectionId = $examSubject->getSection()->getId()->toRfc4122();

            if (!in_array($newSectionId, $currentSectionIds, true)) {
                $session->clearSections();
                $session->addSection($examSubject->getSection());
            }
        }

        $institution = $session->getInstitute();
        $timezone = new \DateTimeZone($institution->getTimezone()?->value ?? 'UTC');

        $availableFrom = $this->buildAvailableFrom($examSubject, $timezone);
        $availableTo = $this->buildAvailableTo($examSubject, $timezone);

        if ($availableFrom !== null) {
            $session->setAvailableFrom($availableFrom);
        }

        if ($availableTo !== null) {
            $session->setAvailableTo($availableTo);
        }

        $session->setTitle($examSubject->getSubject()->getName() . ' - ' . $examSubject->getExamination()->getName());
    }

    private function buildAvailableFrom(ExamSubject $examSubject, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        $date = $examSubject->getExamDate();
        $startTime = $examSubject->getStartTime();

        if ($date === null) {
            return null;
        }

        $timeStr = $startTime !== null ? $startTime->format('H:i') : '00:00';

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $date->format('Y-m-d') . ' ' . $timeStr, $timezone);

        if (!$dt) {
            return null;
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'));
    }

    private function buildAvailableTo(ExamSubject $examSubject, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        $date = $examSubject->getExamDate();
        $endTime = $examSubject->getEndTime();

        if ($date === null) {
            return null;
        }

        $timeStr = $endTime !== null ? $endTime->format('H:i') : '23:59';

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $date->format('Y-m-d') . ' ' . $timeStr, $timezone);

        if (!$dt) {
            return null;
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'));
    }

    private function mapStatus(ExaminationStatus $status): ExamSessionStatus
    {
        return match ($status) {
            ExaminationStatus::Draft => ExamSessionStatus::Draft,
            ExaminationStatus::Scheduled => ExamSessionStatus::Scheduled,
            ExaminationStatus::Ongoing => ExamSessionStatus::Published,
            ExaminationStatus::Completed => ExamSessionStatus::Closed,
            ExaminationStatus::Cancelled => ExamSessionStatus::Cancelled,
            ExaminationStatus::Archived => ExamSessionStatus::Archived,
        };
    }

    /**
     * Finalizes all IN_PROGRESS exam attempts for a closed session.
     *
     * @param ExamSession $session The exam session that was closed
     * @param \DateTimeImmutable $now The timestamp to use for submission
     */
    private function finalizeInProgressAttempts(ExamSession $session, \DateTimeImmutable $now): void
    {
        $inProgressAttempts = $this->examAttemptRepository->findInProgressByExamSession($session->getId());

        if (empty($inProgressAttempts)) {
            return;
        }

        foreach ($inProgressAttempts as $attempt) {
            $isExpired = $attempt->getExpiresAt() !== null && $now >= $attempt->getExpiresAt();

            $workflow = $this->workflowRegistry->get($attempt);

            if ($isExpired && $workflow->can($attempt, 'auto_submit')) {
                $attempt->setSubmittedAt($now);
                $attempt->setAutoSubmittedAt($now);
                if ($attempt->getAllowedDurationSeconds() !== null && $attempt->getStartedAt() !== null) {
                    $expiredAt = $attempt->getExpiresAt();
                    if ($expiredAt !== null) {
                        $elapsed = $expiredAt->getTimestamp() - $attempt->getStartedAt()->getTimestamp();
                        $attempt->setTimeTakenSeconds(max(0, min($elapsed, $attempt->getAllowedDurationSeconds())));
                    }
                }
                $workflow->apply($attempt, 'auto_submit');
                $this->logger->info('Exam attempt {attemptId} auto-submitted due to session closure (sync)', [
                    'attempt_id' => (string) $attempt->getId(),
                    'session_id' => (string) $session->getId(),
                ]);
            } elseif ($workflow->can($attempt, 'submit')) {
                $attempt->setSubmittedAt($now);
                $workflow->apply($attempt, 'submit');
                $this->logger->info('Exam attempt {attemptId} submitted due to session closure (sync)', [
                    'attempt_id' => (string) $attempt->getId(),
                    'session_id' => (string) $session->getId(),
                ]);
            }
        }

        $this->entityManager->flush();
    }
}
