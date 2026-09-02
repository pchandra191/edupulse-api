<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\ExamAttemptMode;
use App\Enum\ExamAttemptStatus;
use App\Repository\ExamAttemptRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    #[ORM\Column(type: Types::STRING, length: 10, name: 'attempt_mode')]
    private string $mode = ExamAttemptMode::Online->value;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $autoSubmittedAt = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $score = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $maxMarks = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $percentage = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $timeTakenSeconds = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true, name: 'allowed_duration_seconds')]
    private ?int $allowedDurationSeconds = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, name: 'expires_at')]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct()
    {
        parent::__construct();
        $this->examAnswers = new ArrayCollection();
        $this->questionProgresses = new ArrayCollection();
        $this->submissionDocuments = new ArrayCollection();
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

    public function getMode(): ExamAttemptMode
    {
        return ExamAttemptMode::from($this->mode);
    }

    public function setMode(ExamAttemptMode $mode): self
    {
        $this->mode = $mode->value;

        return $this;
    }

    public function isOnline(): bool
    {
        return $this->mode === ExamAttemptMode::Online->value;
    }

    public function isOffline(): bool
    {
        return $this->mode === ExamAttemptMode::Offline->value;
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

    public function isEvaluated(): bool
    {
        return $this->status === ExamAttemptStatus::Evaluated->value;
    }

    public function isTerminal(): bool
    {
        return match ($this->status) {
            ExamAttemptStatus::Submitted->value,
            ExamAttemptStatus::AutoSubmitted->value,
            ExamAttemptStatus::Abandoned->value,
            ExamAttemptStatus::Evaluated->value => true,
            default => false,
        };
    }

    #[ORM\OneToMany(mappedBy: 'examAttempt', targetEntity: ExamAnswer::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $examAnswers;

    #[ORM\OneToMany(mappedBy: 'examAttempt', targetEntity: ExamAttemptQuestionProgress::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $questionProgresses;

    #[ORM\OneToMany(mappedBy: 'examAttempt', targetEntity: ExamSubmissionDocument::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $submissionDocuments;

    /**
      * @return Collection<int, ExamAnswer>
      */
    public function getExamAnswers(): Collection
    {
        return $this->examAnswers;
    }

    public function addExamAnswer(ExamAnswer $examAnswer): self
    {
        if (!$this->examAnswers->contains($examAnswer)) {
            $this->examAnswers->add($examAnswer);
            $examAnswer->setExamAttempt($this);
        }

        return $this;
    }

    public function removeExamAnswer(ExamAnswer $examAnswer): self
    {
        if ($this->examAnswers->removeElement($examAnswer)) {
            if ($examAnswer->getExamAttempt() === $this) {
                $examAnswer->setExamAttempt(new ExamAttempt());
            }
        }

        return $this;
    }

    /**
      * @return Collection<int, ExamAttemptQuestionProgress>
      */
    public function getQuestionProgresses(): Collection
    {
        return $this->questionProgresses;
    }

    public function addQuestionProgress(ExamAttemptQuestionProgress $progress): self
    {
        if (!$this->questionProgresses->contains($progress)) {
            $this->questionProgresses->add($progress);
            $progress->setExamAttempt($this);
        }

        return $this;
    }

    public function removeQuestionProgress(ExamAttemptQuestionProgress $progress): self
    {
        if ($this->questionProgresses->removeElement($progress)) {
            if ($progress->getExamAttempt() === $this) {
                $progress->setExamAttempt(new ExamAttempt());
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ExamSubmissionDocument>
     */
    public function getSubmissionDocuments(): Collection
    {
        return $this->submissionDocuments;
    }

    public function addSubmissionDocument(ExamSubmissionDocument $document): self
    {
        if (!$this->submissionDocuments->contains($document)) {
            $this->submissionDocuments->add($document);
            $document->setExamAttempt($this);
        }

        return $this;
    }

    public function removeSubmissionDocument(ExamSubmissionDocument $document): self
    {
        if ($this->submissionDocuments->removeElement($document)) {
            if ($document->getExamAttempt() === $this) {
                $document->setExamAttempt(new ExamAttempt());
            }
        }

        return $this;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): self
    {
        $this->score = $score;

        return $this;
    }

    public function getMaxMarks(): ?int
    {
        return $this->maxMarks;
    }

    public function setMaxMarks(?int $maxMarks): self
    {
        $this->maxMarks = $maxMarks;

        return $this;
    }

    public function getPercentage(): ?int
    {
        return $this->percentage;
    }

    public function setPercentage(?int $percentage): self
    {
        $this->percentage = $percentage;

        return $this;
    }

    public function getTimeTakenSeconds(): ?int
    {
        return $this->timeTakenSeconds;
    }

    public function setTimeTakenSeconds(?int $timeTakenSeconds): self
    {
        $this->timeTakenSeconds = $timeTakenSeconds;

        return $this;
    }

    public function getAllowedDurationSeconds(): ?int
    {
        return $this->allowedDurationSeconds;
    }

    public function setAllowedDurationSeconds(?int $allowedDurationSeconds): self
    {
        $this->allowedDurationSeconds = $allowedDurationSeconds;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $checkTime = $now ?? new \DateTimeImmutable();

        return $checkTime >= $this->expiresAt;
    }

    public function getRemainingSeconds(?\DateTimeImmutable $now = null): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }

        $checkTime = $now ?? new \DateTimeImmutable();
        $remaining = $this->expiresAt->getTimestamp() - $checkTime->getTimestamp();

        return max(0, $remaining);
    }
}
