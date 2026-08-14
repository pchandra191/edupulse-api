<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\StudentEnrollmentStatus;
use App\Repository\StudentEnrollmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: StudentEnrollmentRepository::class)]
#[ORM\Table(name: 'student_enrollments')]
#[ORM\UniqueConstraint(name: 'uniq_enrollment_student_year_class', columns: ['student_id', 'academic_year_id', 'academic_class_id'])]
#[ORM\UniqueConstraint(name: 'uniq_enrollment_roll', columns: ['academic_year_id', 'academic_class_id', 'roll_number'])]
class StudentEnrollment extends AbstractEntity
{
    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: AcademicYear::class)]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: AcademicClass::class)]
    #[ORM\JoinColumn(name: 'academic_class_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicClass $academicClass;

    #[ORM\Column(name: 'institute_id', type: 'uuid', nullable: true)]
    private ?Uuid $instituteId = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $rollNumber = null;

    #[ORM\Column(length: 50)]
    private string $admissionNumber;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $admissionDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $remarks = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isCurrent = false;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = StudentEnrollmentStatus::Active->value;

    #[ORM\OneToMany(mappedBy: 'studentEnrollment', targetEntity: EnrollmentSection::class, cascade: ['persist'], orphanRemoval: false)]
    private Collection $enrollmentSections;

    public function __construct()
    {
        parent::__construct();
        $this->enrollmentSections = new ArrayCollection();
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

    public function getRollNumber(): ?string
    {
        return $this->rollNumber;
    }

    public function setRollNumber(?string $rollNumber): self
    {
        $this->rollNumber = $rollNumber !== null ? trim($rollNumber) : null;

        return $this;
    }

    public function getAdmissionNumber(): string
    {
        return $this->admissionNumber;
    }

    public function setAdmissionNumber(string $admissionNumber): self
    {
        $this->admissionNumber = mb_strtoupper(trim($admissionNumber));

        return $this;
    }

    public function getInstituteId(): ?Uuid
    {
        return $this->instituteId;
    }

    public function setInstituteId(?Uuid $instituteId): self
    {
        $this->instituteId = $instituteId;

        return $this;
    }

    public function getAdmissionDate(): ?\DateTimeImmutable
    {
        return $this->admissionDate;
    }

    public function setAdmissionDate(?\DateTimeImmutable $admissionDate): self
    {
        $this->admissionDate = $admissionDate;

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

    public function isCurrent(): bool
    {
        return $this->isCurrent;
    }

    public function setIsCurrent(bool $isCurrent): self
    {
        $this->isCurrent = $isCurrent;

        return $this;
    }

    public function getStatus(): StudentEnrollmentStatus
    {
        return StudentEnrollmentStatus::from($this->status);
    }

    public function setStatus(StudentEnrollmentStatus $status): self
    {
        $this->status = $status->value;

        return $this;
    }

    public function getEnrollmentSections(): Collection
    {
        return $this->enrollmentSections;
    }

    public function addEnrollmentSection(EnrollmentSection $enrollmentSection): self
    {
        if (!$this->enrollmentSections->contains($enrollmentSection)) {
            $this->enrollmentSections[] = $enrollmentSection;
            $enrollmentSection->setStudentEnrollment($this);
        }

        return $this;
    }

    public function removeEnrollmentSection(EnrollmentSection $enrollmentSection): self
    {
        if ($this->enrollmentSections->removeElement($enrollmentSection)) {
            if ($enrollmentSection->getStudentEnrollment() === $this) {
                $enrollmentSection->setStudentEnrollment(null);
            }
        }

        return $this;
    }
}
