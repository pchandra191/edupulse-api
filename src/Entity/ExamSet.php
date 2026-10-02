<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Analysis;
use App\Repository\ExamSetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ExamSetRepository::class)]
#[ORM\Table(name: 'exam_sets')]
#[ORM\Index(name: 'idx_exam_set_student', fields: ['student'])]
#[ORM\Index(name: 'idx_exam_set_institute', fields: ['institute'])]
#[ORM\Index(name: 'idx_exam_set_academic_year', fields: ['academicYearId'])]
#[ORM\Index(name: 'idx_exam_set_class', fields: ['classId'])]
#[ORM\Index(name: 'idx_exam_set_exam', fields: ['examId'])]
class ExamSet extends AbstractEntity
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\ManyToOne(targetEntity: Institute::class)]
    #[ORM\JoinColumn(name: 'institute_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Institute $institute = null;

    #[ORM\OneToMany(mappedBy: 'examSet', targetEntity: Analysis::class, cascade: ['persist'], orphanRemoval: false)]
    private Collection $analyses;

    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    private ?string $academicYearId = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $academicYear = null;

    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    private ?string $classId = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $academicClass = null;

    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    private ?string $sectionId = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $section = null;

    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    private ?string $examId = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $examName = null;

    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    private ?string $examSubTypeId = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $examSubType = null;

    public function __construct()
    {
        parent::__construct();
        $this->analyses = new ArrayCollection();
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

    public function getInstitute(): ?Institute
    {
        return $this->institute;
    }

    public function setInstitute(?Institute $institute): self
    {
        $this->institute = $institute;
        return $this;
    }

    /**
     * @return Collection<int, Analysis>
     */
    public function getAnalyses(): Collection
    {
        return $this->analyses;
    }

    public function addAnalysis(Analysis $analysis): self
    {
        if (!$this->analyses->contains($analysis)) {
            $this->analyses->add($analysis);
            $analysis->setExamSet($this);
        }
        return $this;
    }

    public function removeAnalysis(Analysis $analysis): self
    {
        if ($this->analyses->removeElement($analysis)) {
            if ($analysis->getExamSet() === $this) {
                $analysis->setExamSet(null);
            }
        }
        return $this;
    }

    public function getAcademicYearId(): ?string
    {
        return $this->academicYearId;
    }

    public function setAcademicYearId(?string $academicYearId): self
    {
        $this->academicYearId = $academicYearId;
        return $this;
    }

    public function getAcademicYear(): ?string
    {
        return $this->academicYear;
    }

    public function setAcademicYear(?string $academicYear): self
    {
        $this->academicYear = $academicYear;
        return $this;
    }

    public function getClassId(): ?string
    {
        return $this->classId;
    }

    public function setClassId(?string $classId): self
    {
        $this->classId = $classId;
        return $this;
    }

    public function getAcademicClass(): ?string
    {
        return $this->academicClass;
    }

    public function setAcademicClass(?string $academicClass): self
    {
        $this->academicClass = $academicClass;
        return $this;
    }

    public function getSectionId(): ?string
    {
        return $this->sectionId;
    }

    public function setSectionId(?string $sectionId): self
    {
        $this->sectionId = $sectionId;
        return $this;
    }

    public function getSection(): ?string
    {
        return $this->section;
    }

    public function setSection(?string $section): self
    {
        $this->section = $section;
        return $this;
    }

    public function getExamId(): ?string
    {
        return $this->examId;
    }

    public function setExamId(?string $examId): self
    {
        $this->examId = $examId;
        return $this;
    }

    public function getExamName(): ?string
    {
        return $this->examName;
    }

    public function setExamName(?string $examName): self
    {
        $this->examName = $examName;
        return $this;
    }

    public function getExamSubTypeId(): ?string
    {
        return $this->examSubTypeId;
    }

    public function setExamSubTypeId(?string $examSubTypeId): self
    {
        $this->examSubTypeId = $examSubTypeId;
        return $this;
    }

    public function getExamSubType(): ?string
    {
        return $this->examSubType;
    }

    public function setExamSubType(?string $examSubType): self
    {
        $this->examSubType = $examSubType;
        return $this;
    }
}