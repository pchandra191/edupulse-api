<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: \App\Repository\StudyTaskRepository::class)]
#[ORM\Table(name: 'study_tasks')]
#[ORM\Index(name: 'idx_study_task_student', fields: ['studentId'])]
#[ORM\Index(name: 'idx_study_task_key', fields: ['aiRecommendationKey'])]
#[ORM\UniqueConstraint(name: 'uniq_study_task_student_key', columns: ['student_id', 'ai_recommendation_key'])]
class StudyTask extends AbstractEntity
{
    #[ORM\Column(type: 'uuid')]
    private ?Uuid $studentId = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $aiRecommendationKey = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $topic = null;

    #[ORM\Column(length: 20)]
    private string $priority = 'medium';

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $estimatedMinutes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isCompleted = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isAiRecommended = false;

    public function __construct()
    {
        parent::__construct();
    }

    public function getStudentId(): ?Uuid
    {
        return $this->studentId;
    }

    public function setStudentId(?Uuid $studentId): self
    {
        $this->studentId = $studentId;

        return $this;
    }

    public function getAiRecommendationKey(): ?string
    {
        return $this->aiRecommendationKey;
    }

    public function setAiRecommendationKey(?string $aiRecommendationKey): self
    {
        $this->aiRecommendationKey = $aiRecommendationKey !== null ? trim($aiRecommendationKey) : null;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = trim($title);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description !== null ? trim($description) : null;

        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): self
    {
        $this->subject = $subject !== null ? trim($subject) : null;

        return $this;
    }

    public function getTopic(): ?string
    {
        return $this->topic;
    }

    public function setTopic(?string $topic): self
    {
        $this->topic = $topic !== null ? trim($topic) : null;

        return $this;
    }

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function setPriority(string $priority): self
    {
        $this->priority = trim($priority);

        return $this;
    }

    public function getEstimatedMinutes(): ?int
    {
        return $this->estimatedMinutes;
    }

    public function setEstimatedMinutes(?int $estimatedMinutes): self
    {
        $this->estimatedMinutes = $estimatedMinutes;

        return $this;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): self
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    public function isCompleted(): bool
    {
        return $this->isCompleted;
    }

    public function setIsCompleted(bool $isCompleted): self
    {
        $this->isCompleted = $isCompleted;

        return $this;
    }

    public function isAiRecommended(): bool
    {
        return $this->isAiRecommended;
    }

    public function setIsAiRecommended(bool $isAiRecommended): self
    {
        $this->isAiRecommended = $isAiRecommended;

        return $this;
    }
}
