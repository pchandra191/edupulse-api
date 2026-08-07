<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\TeacherAssignmentStatus;
use App\Repository\TeacherAssignmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TeacherAssignmentRepository::class)]
#[ORM\Table(name: 'teacher_assignments')]
#[ORM\UniqueConstraint(name: 'uniq_teacher_assignment', columns: ['teacher_id', 'academic_year_id', 'academic_class_id', 'section_id', 'subject_id'])]
class TeacherAssignment extends AbstractEntity
{
    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $teacher;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: AcademicYear::class)]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: AcademicClass::class)]
    #[ORM\JoinColumn(name: 'academic_class_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicClass $academicClass;

    #[ORM\ManyToOne(targetEntity: Section::class)]
    #[ORM\JoinColumn(name: 'section_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Section $section = null;

    #[ORM\ManyToOne(targetEntity: Subject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Subject $subject = null;

    #[ORM\Column(type: Types::STRING, length: 30)]
    private string $assignmentType;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $remarks = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = TeacherAssignmentStatus::Active->value;

    public function __construct()
    {
        parent::__construct();
    }

    public function getTeacher(): User
    {
        return $this->teacher;
    }

    public function setTeacher(User $teacher): self
    {
        $this->teacher = $teacher;

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

    public function getSection(): ?Section
    {
        return $this->section;
    }

    public function setSection(?Section $section): self
    {
        $this->section = $section;

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

    public function getAssignmentType(): string
    {
        return $this->assignmentType;
    }

    public function setAssignmentType(string $assignmentType): self
    {
        $this->assignmentType = $assignmentType;

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

    public function getStatus(): TeacherAssignmentStatus
    {
        return TeacherAssignmentStatus::from($this->status);
    }

    public function setStatus(TeacherAssignmentStatus $status): self
    {
        $this->status = $status->value;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === TeacherAssignmentStatus::Active->value;
    }
}
