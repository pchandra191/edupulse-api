<?php
declare(strict_types=1);

namespace App\Service\ExamAttemptSecurityEventModule;

use App\DTO\ExamAttemptSecurityEventModule\ExamAttemptSecurityEventRequestDTO;
use App\DTO\ExamAttemptSecurityEventModule\ExamAttemptSecurityEventResponseDTO;
use App\Entity\ExamAttempt;
use App\Entity\ExamAttemptSecurityEvent;
use App\Entity\ExamSession;
use App\Entity\User;
use App\Enum\ExamAttemptStatus;
use App\Exception\AuthenticationException;
use App\Repository\ExamAttemptRepository;
use App\Repository\ExamAttemptSecurityEventRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

readonly class ExamAttemptSecurityEventService
{
    public function __construct(
        private Security $security,
        private ExamAttemptRepository $examAttemptRepository,
        private ExamAttemptSecurityEventRepository $securityEventRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function recordSecurityEvent(ExamAttemptSecurityEventRequestDTO $request): ExamAttemptSecurityEventResponseDTO
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('User not authenticated.');
        }

        $attempt = $this->examAttemptRepository->find(Uuid::fromString($request->attemptId));

        if (!$attempt instanceof ExamAttempt) {
            throw new AuthenticationException('Exam attempt not found.');
        }

        if (!$attempt->getStudent()->getId()->equals($user->getId())) {
            throw new AuthenticationException('You are not authorized to record security events for this exam attempt.');
        }

        $examSession = $attempt->getExamSession();

        if (!$examSession->getId()->equals(Uuid::fromString($request->examSessionId))) {
            throw new \App\Common\Exception\BusinessException('Mismatched exam session ID for this exam attempt.');
        }

        if (!$attempt->isInProgress()) {
            throw new \App\Common\Exception\BusinessException('Security events can only be recorded for an active exam attempt.');
        }

        $startedAt = $attempt->getStartedAt();
        $submittedAt = $attempt->getSubmittedAt();

        if ($startedAt === null || $request->occurredAt < $startedAt) {
            throw new \App\Common\Exception\BusinessException('Event timestamp cannot be before the exam attempt started.');
        }

        if ($submittedAt !== null && $request->occurredAt > $submittedAt) {
            throw new \App\Common\Exception\BusinessException('Security events cannot be recorded after the exam attempt was submitted.');
        }

        $event = new ExamAttemptSecurityEvent();
        $event->setExamAttempt($attempt);
        $event->setExamSession($examSession);
        $event->setStudent($attempt->getStudent());
        $event->setEventType($request->eventType);
        $event->setCount($request->count);
        $event->setOccurredAt($request->occurredAt);

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return new ExamAttemptSecurityEventResponseDTO(
            id: $event->getId()->toRfc4122(),
            attemptId: $attempt->getId()->toRfc4122(),
            examSessionId: $examSession->getId()->toRfc4122(),
            eventType: $request->eventType->value,
            count: $request->count,
            occurredAt: $request->occurredAt->format(DateTimeInterface::ATOM),
            createdAt: $event->getCreatedAt()?->format(DateTimeInterface::ATOM) ?? '',
        );
    }

    public function getSecurityEventsByAttempt(Uuid $attemptId): array
    {
        $events = $this->securityEventRepository->findByAttempt($attemptId);

        return array_map(
            fn (ExamAttemptSecurityEvent $event) => new ExamAttemptSecurityEventResponseDTO(
                id: $event->getId()->toRfc4122(),
                attemptId: $event->getExamAttempt()->getId()->toRfc4122(),
                examSessionId: $event->getExamSession()->getId()->toRfc4122(),
                eventType: $event->getEventType()->value,
                count: $event->getCount(),
                occurredAt: $event->getOccurredAt()?->format(DateTimeInterface::ATOM) ?? '',
                createdAt: $event->getCreatedAt()?->format(DateTimeInterface::ATOM) ?? '',
            ),
            $events,
        );
    }
}
