<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\ExaminationStatus;
use App\Repository\ExaminationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ExaminationRepository::class)]
#[ORM\Table(name: 'examinations')]
#[ORM\Index(name: 'idx_examination_institute', fields: ['institute'])]
#[ORM\Index(name: 'idx_examination_academic_year', fields: ['academicYear'])]
#[ORM\Index(name: 'idx_examination_academic_class', fields: ['academicClass'])]
#[ORM\Index(name: 'idx_examination_status', fields: ['status'])]
#[ORM\UniqueConstraint(name: 'uniq_examination_institute_code', columns: ['institute_id', 'code'])]
class Examination extends AbstractEntity
{
    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: Institute::class, inversedBy: 'examinations')]
    #[ORM\JoinColumn(name: 'institute_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institute $institute;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: AcademicYear::class, inversedBy: 'examinations')]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: AcademicClass::class, inversedBy: 'examinations')]
    #[ORM\JoinColumn(name: 'academic_class_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicClass $academicClass;

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

    #[Assert\NotBlank]
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startDate;

    #[Assert\NotBlank]
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endDate;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = ExaminationStatus::Draft->value;

    #[ORM\OneToMany(mappedBy: 'examination', targetEntity: ExamSubject::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $examSubjects;

    public function __construct()
    {
        parent::__construct();
        $this->examSubjects = new ArrayCollection();
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

    public function getAcademicClass(): AcademicClass
    {
        return $this->academicClass;
    }

    public function setAcademicClass(AcademicClass $academicClass): self
    {
        $this->academicClass = $academicClass;

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

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeImmutable $startDate): self
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(\DateTimeImmutable $endDate): self
    {
        $this->endDate = $endDate;

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

    public function getExamSubjects(): Collection
    {
        return $this->examSubjects;
    }

    public function addExamSubject(ExamSubject $examSubject): self
    {
        if (!$this->examSubjects->contains($examSubject)) {
            $this->examSubjects[] = $examSubject;
            $examSubject->setExamination($this);
        }

        return $this;
    }

    public function removeExamSubject(ExamSubject $examSubject): self
    {
        if ($this->examSubjects->removeElement($examSubject)) {
            if ($examSubject->getExamination() === $this) {
                $examSubject->setExamination(new Examination());
            }
        }

        return $this;
    }
}
