<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\ExamSessionStatus;
use App\Repository\ExamSessionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ExamSessionRepository::class)]
#[ORM\Table(name: 'exam_sessions')]
#[ORM\Index(name: 'idx_exam_session_institute', fields: ['institute_id'])]
#[ORM\Index(name: 'idx_exam_session_academic_year', fields: ['academic_year_id'])]
#[ORM\Index(name: 'idx_exam_session_academic_class', fields: ['academic_class_id'])]
#[ORM\Index(name: 'idx_exam_session_question_paper', fields: ['question_paper_id'])]
#[ORM\Index(name: 'idx_exam_session_status', fields: ['status'])]
#[ORM\UniqueConstraint(name: 'uniq_exam_session_institute_title', columns: ['institute_id', 'title'])]
class ExamSession extends AbstractEntity
{
    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: Institute::class)]
    #[ORM\JoinColumn(name: 'institute_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institute $institute;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: AcademicYear::class, inversedBy: 'examSessions')]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: AcademicClass::class, inversedBy: 'examSessions')]
    #[ORM\JoinColumn(name: 'academic_class_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicClass $academicClass;

    #[ORM\ManyToMany(targetEntity: Section::class)]
    #[ORM\JoinTable(name: 'exam_session_sections')]
    #[ORM\JoinColumn(name: 'exam_session_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'section_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $sections;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: QuestionPaper::class)]
    #[ORM\JoinColumn(name: 'question_paper_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private QuestionPaper $questionPaper;

    #[ORM\ManyToOne(targetEntity: Examination::class)]
    #[ORM\JoinColumn(name: 'examination_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Examination $examination = null;

    #[ORM\ManyToOne(targetEntity: ExamSubject::class)]
    #[ORM\JoinColumn(name: 'exam_subject_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ExamSubject $examSubject = null;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $createdBy;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[Assert\NotBlank]
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $availableFrom;

    #[Assert\NotBlank]
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $availableTo;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $durationOverride = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $shuffleQuestions = false;

    #[ORM\Column(length: 20)]
    private string $showResultsMode = 'IMMEDIATE';

    #[ORM\Column(length: 20)]
    private string $accessMode = 'OPEN';

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $allowReview = true;

    #[ORM\Column(type: Types::INTEGER)]
    private int $totalMarks = 0;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = ExamSessionStatus::Draft->value;

    #[ORM\OneToMany(mappedBy: 'examSession', targetEntity: ExamAttempt::class, cascade: ['persist'], orphanRemoval: false)]
    private Collection $examAttempts;

    public function __construct()
    {
        parent::__construct();
        $this->sections = new ArrayCollection();
        $this->examAttempts = new ArrayCollection();
    }

    public function getInstitute(): Institute
    {
        return $this->institute;
    }

    public function setInstitute(Institute $institute): self
    {
        $this->institute = $institute;

        return $this;
    }

    public function getQuestionPaper(): QuestionPaper
    {
        return $this->questionPaper;
    }

    public function setQuestionPaper(QuestionPaper $questionPaper): self
    {
        $this->questionPaper = $questionPaper;

        return $this;
    }

    public function getExamination(): ?Examination
    {
        return $this->examination;
    }

    public function setExamination(?Examination $examination): self
    {
        $this->examination = $examination;

        return $this;
    }

    public function getExamSubject(): ?ExamSubject
    {
        return $this->examSubject;
    }

    public function setExamSubject(?ExamSubject $examSubject): self
    {
        $this->examSubject = $examSubject;

        return $this;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): self
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function getAvailableFrom(): \DateTimeImmutable
    {
        return $this->availableFrom;
    }

    public function setAvailableFrom(\DateTimeImmutable $availableFrom): self
    {
        $this->availableFrom = $availableFrom;

        return $this;
    }

    public function getAvailableTo(): \DateTimeImmutable
    {
        return $this->availableTo;
    }

    public function setAvailableTo(\DateTimeImmutable $availableTo): self
    {
        $this->availableTo = $availableTo;

        return $this;
    }

    public function getDurationOverride(): ?int
    {
        return $this->durationOverride;
    }

    public function setDurationOverride(?int $durationOverride): self
    {
        $this->durationOverride = $durationOverride;

        return $this;
    }

    public function isShuffleQuestions(): bool
    {
        return $this->shuffleQuestions;
    }

    public function setShuffleQuestions(bool $shuffleQuestions): self
    {
        $this->shuffleQuestions = $shuffleQuestions;

        return $this;
    }

    public function getShowResultsMode(): string
    {
        return $this->showResultsMode;
    }

    public function setShowResultsMode(string $showResultsMode): self
    {
        $this->showResultsMode = $showResultsMode;

        return $this;
    }

    public function getAccessMode(): string
    {
        return $this->accessMode;
    }

    public function setAccessMode(string $accessMode): self
    {
        $this->accessMode = $accessMode;

        return $this;
    }

    public function isAllowReview(): bool
    {
        return $this->allowReview;
    }

    public function setAllowReview(bool $allowReview): self
    {
        $this->allowReview = $allowReview;

        return $this;
    }

    public function getTotalMarks(): int
    {
        return $this->totalMarks;
    }

    public function setTotalMarks(int $totalMarks): self
    {
        $this->totalMarks = $totalMarks;

        return $this;
    }

    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
    }

    public function setAcademicYear(AcademicYear $academicYear): self
    {
        $this->academicYear = $academicYear;

        return $this;
    }

    public function getAcademicClass(): AcademicClass
    {
        return $this->academicClass;
    }

    public function setAcademicClass(AcademicClass $academicClass): self
    {
        $this->academicClass = $academicClass;

        return $this;
    }

    /**
     * @return Collection<int, Section>
     */
    public function getSections(): Collection
    {
        return $this->sections;
    }

    public function addSection(Section $section): self
    {
        if (!$this->sections->contains($section)) {
            $this->sections->add($section);
        }

        return $this;
    }

    public function removeSection(Section $section): self
    {
        $this->sections->removeElement($section);

        return $this;
    }

    public function clearSections(): self
    {
        $this->sections->clear();

        return $this;
    }

    public function getStatus(): ExamSessionStatus
    {
        return ExamSessionStatus::from($this->status);
    }

    public function setStatus(ExamSessionStatus $status): self
    {
        $this->status = $status->value;

        return $this;
    }

    public function isDraft(): bool
    {
        return $this->status === ExamSessionStatus::Draft->value;
    }

    public function isScheduled(): bool
    {
        return $this->status === ExamSessionStatus::Scheduled->value;
    }

    public function isPublished(): bool
    {
        return $this->status === ExamSessionStatus::Published->value;
    }

    public function isActive(): bool
    {
        return $this->status === ExamSessionStatus::Active->value;
    }

    public function isClosed(): bool
    {
        return $this->status === ExamSessionStatus::Closed->value;
    }

    public function isCancelled(): bool
    {
        return $this->status === ExamSessionStatus::Cancelled->value;
    }

    public function isArchived(): bool
    {
        return $this->status === ExamSessionStatus::Archived->value;
    }

    /**
     * @return Collection<int, ExamAttempt>
     */
    public function getExamAttempts(): Collection
    {
        return $this->examAttempts;
    }

    public function addExamAttempt(ExamAttempt $examAttempt): self
    {
        if (!$this->examAttempts->contains($examAttempt)) {
            $this->examAttempts->add($examAttempt);
            $examAttempt->setExamSession($this);
        }

        return $this;
    }

    public function removeExamAttempt(ExamAttempt $examAttempt): self
    {
        if ($this->examAttempts->removeElement($examAttempt)) {
            if ($examAttempt->getExamSession() === $this) {
                $examAttempt->setExamSession(new ExamSession());
            }
        }

        return $this;
    }
}
