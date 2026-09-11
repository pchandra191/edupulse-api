<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\ExaminationStatus;
use App\Repository\ExamSubjectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ExamSubjectRepository::class)]
#[ORM\Table(name: 'exam_subjects')]
#[ORM\Index(name: 'idx_exam_subject_examination', fields: ['examination'])]
#[ORM\Index(name: 'idx_exam_subject_subject', fields: ['subject'])]
#[ORM\Index(name: 'idx_exam_subject_section', fields: ['section'])]
#[ORM\Index(name: 'idx_exam_subject_status', fields: ['status'])]
class ExamSubject extends AbstractEntity
{
    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: Examination::class, inversedBy: 'examSubjects')]
    #[ORM\JoinColumn(name: 'examination_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Examination $examination;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: Subject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Subject $subject;

    #[ORM\ManyToOne(targetEntity: Section::class)]
    #[ORM\JoinColumn(name: 'section_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Section $section = null;

    #[Assert\NotBlank]
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $examDate = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startTime = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endTime = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $duration = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $maxMarks = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $passingMarks = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = ExaminationStatus::Draft->value;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $remarks = null;

    #[ORM\ManyToOne(targetEntity: QuestionPaper::class)]
    #[ORM\JoinColumn(name: 'question_paper_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?QuestionPaper $questionPaper = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isValidated = false;

    public function __construct()
    {
        parent::__construct();
    }

    public function getExamination(): Examination
    {
        return $this->examination;
    }

    public function setExamination(Examination $examination): self
    {
        $this->examination = $examination;

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

    public function getSection(): ?Section
    {
        return $this->section;
    }

    public function setSection(?Section $section): self
    {
        $this->section = $section;

        return $this;
    }

    public function getExamDate(): ?\DateTimeImmutable
    {
        return $this->examDate;
    }

    public function setExamDate(?\DateTimeImmutable $examDate): self
    {
        $this->examDate = $examDate;

        return $this;
    }

    public function getStartTime(): ?\DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setStartTime(?\DateTimeImmutable $startTime): self
    {
        $this->startTime = $startTime;

        return $this;
    }

    public function getEndTime(): ?\DateTimeImmutable
    {
        return $this->endTime;
    }

    public function setEndTime(?\DateTimeImmutable $endTime): self
    {
        $this->endTime = $endTime;

        return $this;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function setDuration(?int $duration): self
    {
        $this->duration = $duration;

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

    public function getPassingMarks(): ?int
    {
        return $this->passingMarks;
    }

    public function setPassingMarks(?int $passingMarks): self
    {
        $this->passingMarks = $passingMarks;

        return $this;
    }

    public function getStatus(): ExaminationStatus
    {
        return ExaminationStatus::from($this->status);
    }

    public function setStatus(ExaminationStatus $status): self
    {
        $this->status = $status->value;

        return $this;
    }

    public function getRemarks(): ?string
    {
        return $this->remarks;
    }

    public function setRemarks(?string $remarks): self
    {
        $this->remarks = $remarks !== null ? trim($remarks) : null;

        return $this;
    }

    public function getQuestionPaper(): ?QuestionPaper
    {
        return $this->questionPaper;
    }

    public function setQuestionPaper(?QuestionPaper $questionPaper): self
    {
        $this->questionPaper = $questionPaper;

        return $this;
    }

    public function isValidated(): bool
    {
        return $this->isValidated;
    }

    public function setValidated(bool $isValidated): self
    {
        $this->isValidated = $isValidated;

        return $this;
    }
}
