<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\ExamAttemptStatus;
use App\Repository\ExamAttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ExamAttemptRepository::class)]
#[ORM\Table(name: 'exam_attempts')]
#[ORM\Index(name: 'idx_exam_attempt_session_student', columns: ['exam_session_id', 'student_id'])]
#[ORM\Index(name: 'idx_exam_attempt_student', columns: ['student_id'])]
#[ORM\Index(name: 'idx_exam_attempt_status', columns: ['status'])]
class ExamAttempt extends AbstractEntity
{
    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: ExamSession::class, inversedBy: 'examAttempts')]
    #[ORM\JoinColumn(name: 'exam_session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ExamSession $examSession;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'examAttempts')]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = ExamAttemptStatus::NotStarted->value;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $autoSubmittedAt = null;

    public function __construct()
    {
        parent::__construct();
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

    public function getStatus(): ExamAttemptStatus
    {
        return ExamAttemptStatus::from($this->status);
    }

    public function setStatus(ExamAttemptStatus $status): self
    {
        $this->status = $status->value;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(?\DateTimeImmutable $submittedAt): self
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }

    public function getAutoSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->autoSubmittedAt;
    }

    public function setAutoSubmittedAt(?\DateTimeImmutable $autoSubmittedAt): self
    {
        $this->autoSubmittedAt = $autoSubmittedAt;

        return $this;
    }

    public function isNotStarted(): bool
    {
        return $this->status === ExamAttemptStatus::NotStarted->value;
    }

    public function isInProgress(): bool
    {
        return $this->status === ExamAttemptStatus::InProgress->value;
    }

    public function isSubmitted(): bool
    {
        return $this->status === ExamAttemptStatus::Submitted->value;
    }

    public function isAutoSubmitted(): bool
    {
        return $this->status === ExamAttemptStatus::AutoSubmitted->value;
    }

    public function isAbandoned(): bool
    {
        return $this->status === ExamAttemptStatus::Abandoned->value;
    }

    public function isTerminal(): bool
    {
        return match ($this->status) {
            ExamAttemptStatus::Submitted->value,
            ExamAttemptStatus::AutoSubmitted->value,
            ExamAttemptStatus::Abandoned->value => true,
            default => false,
        };
    }
}
