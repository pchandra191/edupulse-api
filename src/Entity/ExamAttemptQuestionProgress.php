<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\ExamQuestionProgressStatus;
use App\Repository\ExamAttemptQuestionProgressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExamAttemptQuestionProgressRepository::class)]
#[ORM\Table(name: 'exam_attempt_question_progress')]
#[ORM\Index(name: 'idx_progress_attempt', fields: ['examAttempt'])]
#[ORM\Index(name: 'idx_progress_question', fields: ['question'])]
#[ORM\UniqueConstraint(name: 'uniq_progress_attempt_question', columns: ['exam_attempt_id', 'question_id'])]
class ExamAttemptQuestionProgress extends AbstractEntity
{
    #[ORM\ManyToOne(targetEntity: ExamAttempt::class, inversedBy: 'questionProgresses')]
    #[ORM\JoinColumn(name: 'exam_attempt_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ExamAttempt $examAttempt;

    #[ORM\ManyToOne(targetEntity: Question::class)]
    #[ORM\JoinColumn(name: 'question_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Question $question;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstViewedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastViewedAt = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $totalTimeSeconds = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $visitCount = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $navigationCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstAnsweredAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastAnsweredAt = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $answerChangeCount = 0;

    #[ORM\Column(type: Types::STRING, length: 30)]
    private string $status = ExamQuestionProgressStatus::NotAttempted->value;

    public function getExamAttempt(): ExamAttempt
    {
        return $this->examAttempt;
    }

    public function setExamAttempt(ExamAttempt $examAttempt): self
    {
        $this->examAttempt = $examAttempt;

        return $this;
    }

    public function getQuestion(): Question
    {
        return $this->question;
    }

    public function setQuestion(Question $question): self
    {
        $this->question = $question;

        return $this;
    }

    public function getFirstViewedAt(): ?\DateTimeImmutable
    {
        return $this->firstViewedAt;
    }

    public function setFirstViewedAt(?\DateTimeImmutable $firstViewedAt): self
    {
        $this->firstViewedAt = $firstViewedAt;

        return $this;
    }

    public function getLastViewedAt(): ?\DateTimeImmutable
    {
        return $this->lastViewedAt;
    }

    public function setLastViewedAt(?\DateTimeImmutable $lastViewedAt): self
    {
        $this->lastViewedAt = $lastViewedAt;

        return $this;
    }

    public function getTotalTimeSeconds(): int
    {
        return $this->totalTimeSeconds;
    }

    public function setTotalTimeSeconds(int $totalTimeSeconds): self
    {
        $this->totalTimeSeconds = $totalTimeSeconds;

        return $this;
    }

    public function getVisitCount(): int
    {
        return $this->visitCount;
    }

    public function setVisitCount(int $visitCount): self
    {
        $this->visitCount = $visitCount;

        return $this;
    }

    public function getNavigationCount(): int
    {
        return $this->navigationCount;
    }

    public function setNavigationCount(int $navigationCount): self
    {
        $this->navigationCount = $navigationCount;

        return $this;
    }

    public function getFirstAnsweredAt(): ?\DateTimeImmutable
    {
        return $this->firstAnsweredAt;
    }

    public function setFirstAnsweredAt(?\DateTimeImmutable $firstAnsweredAt): self
    {
        $this->firstAnsweredAt = $firstAnsweredAt;

        return $this;
    }

    public function getLastAnsweredAt(): ?\DateTimeImmutable
    {
        return $this->lastAnsweredAt;
    }

    public function setLastAnsweredAt(?\DateTimeImmutable $lastAnsweredAt): self
    {
        $this->lastAnsweredAt = $lastAnsweredAt;

        return $this;
    }

    public function getAnswerChangeCount(): int
    {
        return $this->answerChangeCount;
    }

    public function setAnswerChangeCount(int $answerChangeCount): self
    {
        $this->answerChangeCount = $answerChangeCount;

        return $this;
    }

    public function getStatus(): ExamQuestionProgressStatus
    {
        return ExamQuestionProgressStatus::from($this->status);
    }

    public function setStatus(ExamQuestionProgressStatus $status): self
    {
        $this->status = $status->value;

        return $this;
    }
}
