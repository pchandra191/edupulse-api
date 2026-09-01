<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\QuestionPaperStatus;
use App\Repository\QuestionPaperRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: QuestionPaperRepository::class)]
#[ORM\Table(name: 'question_papers')]
#[ORM\Index(name: 'idx_question_paper_institute', fields: ['institute'])]
#[ORM\Index(name: 'idx_question_paper_academic_year', fields: ['academicYear'])]
#[ORM\Index(name: 'idx_question_paper_subject', fields: ['subject'])]
#[ORM\Index(name: 'idx_question_paper_examination', fields: ['examination'])]
#[ORM\Index(name: 'idx_question_paper_status', fields: ['status'])]
#[ORM\UniqueConstraint(name: 'uniq_question_paper_institute_title', columns: ['institute_id', 'title'])]
class QuestionPaper extends AbstractEntity
{
    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: Institute::class, inversedBy: 'questionPapers')]
    #[ORM\JoinColumn(name: 'institute_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institute $institute;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: AcademicYear::class, inversedBy: 'questionPapers')]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: Subject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Subject $subject;

    #[ORM\ManyToMany(targetEntity: AcademicClass::class)]
    #[ORM\JoinTable(name: 'question_paper_classes')]
    #[ORM\JoinColumn(name: 'question_paper_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'class_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $classes;

    #[ORM\ManyToMany(targetEntity: Section::class)]
    #[ORM\JoinTable(name: 'question_paper_sections')]
    #[ORM\JoinColumn(name: 'question_paper_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'section_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $sections;

    #[ORM\ManyToOne(targetEntity: Examination::class)]
    #[ORM\JoinColumn(name: 'examination_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Examination $examination = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $title;

    #[Assert\NotBlank]
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[Assert\NotBlank]
    #[Assert\Positive]
    #[ORM\Column(type: Types::INTEGER)]
    private int $duration;

    #[Assert\NotBlank]
    #[Assert\PositiveOrZero]
    #[ORM\Column(type: Types::INTEGER)]
    private int $maximumMarks;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $instructions = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = QuestionPaperStatus::Draft->value;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\OneToMany(mappedBy: 'questionPaper', targetEntity: Question::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $questions;

    public function __construct()
    {
        parent::__construct();
        $this->classes = new ArrayCollection();
        $this->sections = new ArrayCollection();
        $this->questions = new ArrayCollection();
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

    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
    }

    public function setAcademicYear(AcademicYear $academicYear): self
    {
        $this->academicYear = $academicYear;

        return $this;
    }

    public function getSubject(): Subject
    {
        return $this->subject;
    }

    public function setSubject(Subject $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function getClasses(): Collection
    {
        return $this->classes;
    }

    public function addClass(AcademicClass $class): self
    {
        if (!$this->classes->contains($class)) {
            $this->classes[] = $class;
        }

        return $this;
    }

    public function removeClass(AcademicClass $class): self
    {
        $this->classes->removeElement($class);

        return $this;
    }

    public function getSections(): Collection
    {
        return $this->sections;
    }

    public function addSection(Section $section): self
    {
        if (!$this->sections->contains($section)) {
            $this->sections[] = $section;
        }

        return $this;
    }

    public function removeSection(Section $section): self
    {
        $this->sections->removeElement($section);

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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getDuration(): int
    {
        return $this->duration;
    }

    public function setDuration(int $duration): self
    {
        $this->duration = $duration;

        return $this;
    }

    public function getMaximumMarks(): int
    {
        return $this->maximumMarks;
    }

    public function setMaximumMarks(int $maximumMarks): self
    {
        $this->maximumMarks = $maximumMarks;

        return $this;
    }

    public function getInstructions(): ?string
    {
        return $this->instructions;
    }

    public function setInstructions(?string $instructions): self
    {
        $this->instructions = $instructions !== null ? trim($instructions) : null;

        return $this;
    }

    public function getStatus(): QuestionPaperStatus
    {
        return QuestionPaperStatus::from($this->status);
    }

    public function setStatus(QuestionPaperStatus $status): self
    {
        $this->status = $status->value;

        return $this;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?string $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getQuestions(): Collection
    {
        return $this->questions;
    }

    public function addQuestion(Question $question): self
    {
        if (!$this->questions->contains($question)) {
            $this->questions[] = $question;
            $question->setQuestionPaper($this);
        }

        return $this;
    }

    public function removeQuestion(Question $question): self
    {
        if ($this->questions->removeElement($question)) {
            if ($question->getQuestionPaper() === $this) {
                $question->setQuestionPaper(new QuestionPaper());
            }
        }

        return $this;
    }

    public function isDraft(): bool
    {
        return $this->status === QuestionPaperStatus::Draft->value;
    }

    public function isReview(): bool
    {
        return $this->status === QuestionPaperStatus::Review->value;
    }

    public function isFinalized(): bool
    {
        return $this->status === QuestionPaperStatus::Finalized->value;
    }

    public function isArchived(): bool
    {
        return $this->status === QuestionPaperStatus::Archived->value;
    }
}
