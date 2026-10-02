<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AnalysisSource;
use App\Enum\AnalysisStatus;
use App\Repository\AnalysisRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AnalysisRepository::class)]
#[ORM\Table(name: 'analyses')]
#[ORM\Index(name: 'idx_analysis_student', fields: ['student'])]
#[ORM\Index(name: 'idx_analysis_status', fields: ['status'])]
#[ORM\Index(name: 'idx_analysis_source', fields: ['source'])]
#[ORM\Index(name: 'idx_analysis_created_at', fields: ['createdAt'])]
#[ORM\Index(name: 'idx_analysis_exam_set', fields: ['examSet'])]
#[ORM\Index(name: 'idx_analysis_subject', fields: ['subject'])]
#[ORM\UniqueConstraint(name: 'uniq_analysis_exam_set_subject', columns: ['exam_set_id', 'subject_id'])]
class Analysis extends AbstractEntity
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\ManyToOne(targetEntity: ExamSet::class, inversedBy: 'analyses')]
    #[ORM\JoinColumn(name: 'exam_set_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ExamSet $examSet = null;

    #[ORM\ManyToOne(targetEntity: Subject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Subject $subject = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $subjectName = null;

    #[ORM\Column(type: Types::STRING, length: 36, unique: true)]
    private string $analysisId;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $source = AnalysisSource::ExternalUpload->value;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $status = AnalysisStatus::Draft->value;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $totalMarks = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $scoredMarks = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $studentName = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $rollNumber = null;

    public function __construct()
    {
        parent::__construct();
        $this->analysisId = Uuid::v7()->toRfc4122();
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

    public function getExamSet(): ?ExamSet
    {
        return $this->examSet;
    }

    public function setExamSet(?ExamSet $examSet): self
    {
        $this->examSet = $examSet;
        return $this;
    }

    public function getSubject(): ?Subject
    {
        return $this->subject;
    }

    public function setSubject(?Subject $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function getSubjectName(): ?string
    {
        return $this->subjectName;
    }

    public function setSubjectName(?string $subjectName): self
    {
        $this->subjectName = $subjectName;
        return $this;
    }

    public function getAnalysisId(): string
    {
        return $this->analysisId;
    }

    public function setAnalysisId(string $analysisId): self
    {
        $this->analysisId = $analysisId;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getTotalMarks(): ?int
    {
        return $this->totalMarks;
    }

    public function setTotalMarks(?int $totalMarks): self
    {
        $this->totalMarks = $totalMarks;
        return $this;
    }

    public function getScoredMarks(): ?int
    {
        return $this->scoredMarks;
    }

    public function setScoredMarks(?int $scoredMarks): self
    {
        $this->scoredMarks = $scoredMarks;
        return $this;
    }

    public function getStudentName(): ?string
    {
        return $this->studentName;
    }

    public function setStudentName(?string $studentName): self
    {
        $this->studentName = $studentName;
        return $this;
    }

    public function getRollNumber(): ?string
    {
        return $this->rollNumber;
    }

    public function setRollNumber(?string $rollNumber): self
    {
        $this->rollNumber = $rollNumber;
        return $this;
    }
}