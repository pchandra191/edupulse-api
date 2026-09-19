<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\ClassStatus;
use App\Repository\AcademicClassRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AcademicClassRepository::class)]
#[ORM\Table(name: 'classes')]
#[ORM\Index(name: 'idx_class_status', fields: ['status'])]
#[ORM\UniqueConstraint(name: 'uniq_class_institute_code', columns: ['institute_id', 'code'])]
class AcademicClass extends AbstractEntity
{
    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: Institute::class, inversedBy: 'classes')]
    #[ORM\JoinColumn(name: 'institute_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institute $institute;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: AcademicYear::class, inversedBy: 'classes')]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100)]
    private string $name;

    #[Assert\NotBlank]
    #[Assert\Length(max: 30)]
    #[ORM\Column(length: 30)]
    private string $code;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = ClassStatus::Active->value;

    #[ORM\OneToMany(mappedBy: 'academicClass', targetEntity: ClassSubject::class, cascade: ['persist'], orphanRemoval: false)]
    private Collection $classSubjects;

    #[ORM\OneToMany(mappedBy: 'academicClass', targetEntity: Section::class, cascade: ['persist'], orphanRemoval: false)]
    private Collection $sections;

    #[ORM\OneToMany(mappedBy: 'academicClass', targetEntity: Examination::class, cascade: ['persist'], orphanRemoval: false)]
    private Collection $examinations;

    #[ORM\OneToMany(mappedBy: 'academicClass', targetEntity: ExamSession::class, cascade: ['persist'], orphanRemoval: false)]
    private Collection $examSessions;

    public function __construct()
    {
        parent::__construct();
        $this->classSubjects = new ArrayCollection();
        $this->sections = new ArrayCollection();
        $this->examinations = new ArrayCollection();
        $this->examSessions = new ArrayCollection();
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = mb_strtoupper($code);

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

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): self
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): self
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getStatus(): ClassStatus
    {
        return ClassStatus::from($this->status);
    }

    public function setStatus(ClassStatus $status): self
    {
        $this->status = $status->value;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === ClassStatus::Active->value;
    }

    public function getClassSubjects(): Collection
    {
        return $this->classSubjects;
    }

    public function getSections(): Collection
    {
        return $this->sections;
    }

    public function addSection(Section $section): self
    {
        if (!$this->sections->contains($section)) {
            $this->sections[] = $section;
            $section->setAcademicClass($this);
        }

        return $this;
    }

    public function removeSection(Section $section): self
    {
        if ($this->sections->removeElement($section)) {
            if ($section->getAcademicClass() === $this) {
                $section->setAcademicClass(new AcademicClass());
            }
        }

        return $this;
    }

    public function getExaminations(): Collection
    {
        return $this->examinations;
    }

    public function addExamination(Examination $examination): self
    {
        if (!$this->examinations->contains($examination)) {
            $this->examinations[] = $examination;
            $examination->setAcademicClass($this);
        }

        return $this;
    }

    public function removeExamination(Examination $examination): self
    {
        if ($this->examinations->removeElement($examination)) {
            if ($examination->getAcademicClass() === $this) {
                $examination->setAcademicClass(new AcademicClass());
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ExamSession>
     */
    public function getExamSessions(): Collection
    {
        return $this->examSessions;
    }

    public function addExamSession(ExamSession $examSession): self
    {
        if (!$this->examSessions->contains($examSession)) {
            $this->examSessions[] = $examSession;
            $examSession->setAcademicClass($this);
        }

        return $this;
    }

    public function removeExamSession(ExamSession $examSession): self
    {
        if ($this->examSessions->removeElement($examSession)) {
            if ($examSession->getAcademicClass() === $this) {
                $examSession->setAcademicClass(new AcademicClass());
            }
        }

        return $this;
    }
}
