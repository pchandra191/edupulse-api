<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\ExamSecurityEventType;
use App\Repository\ExamAttemptSecurityEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExamAttemptSecurityEventRepository::class)]
#[ORM\Table(name: 'exam_attempt_security_events')]
#[ORM\Index(name: 'idx_security_event_attempt', columns: ['exam_attempt_id'])]
#[ORM\Index(name: 'idx_security_event_session', columns: ['exam_session_id'])]
#[ORM\Index(name: 'idx_security_event_student', columns: ['student_id'])]
#[ORM\Index(name: 'idx_security_event_type', columns: ['event_type'])]
#[ORM\Index(name: 'idx_security_event_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_security_event_attempt_occurred', columns: ['exam_attempt_id', 'occurred_at'])]
class ExamAttemptSecurityEvent extends AbstractEntity
{
    #[ORM\ManyToOne(targetEntity: ExamAttempt::class)]
    #[ORM\JoinColumn(name: 'exam_attempt_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ExamAttempt $examAttempt;

    #[ORM\ManyToOne(targetEntity: ExamSession::class)]
    #[ORM\JoinColumn(name: 'exam_session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ExamSession $examSession;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\Column(type: Types::STRING, length: 30)]
    private string $eventType;

    #[ORM\Column(type: Types::INTEGER)]
    private int $count = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $occurredAt = null;

    public function getExamAttempt(): ExamAttempt
    {
        return $this->examAttempt;
    }

    public function setExamAttempt(ExamAttempt $examAttempt): self
    {
        $this->examAttempt = $examAttempt;

        return $this;
    }

    public function getExamSession(): ExamSession
    {
        return $this->examSession;
    }

    public function setExamSession(ExamSession $examSession): self
    {
        $this->examSession = $examSession;

        return $this;
    }

    public function getStudent(): User
    {
        return $this->student;
    }

    public function setStudent(User $student): self
    {
        $this->student = $student;

        return $this;
    }

    public function getEventType(): ExamSecurityEventType
    {
        return ExamSecurityEventType::from($this->eventType);
    }

    public function setEventType(ExamSecurityEventType $eventType): self
    {
        $this->eventType = $eventType->value;

        return $this;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function setCount(int $count): self
    {
        $this->count = $count;

        return $this;
    }

    public function getOccurredAt(): ?\DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(?\DateTimeImmutable $occurredAt): self
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }
}
