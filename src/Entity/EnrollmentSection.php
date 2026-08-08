<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\EnrollmentSectionStatus;
use App\Repository\EnrollmentSectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EnrollmentSectionRepository::class)]
#[ORM\Table(name: 'enrollment_sections')]
#[ORM\UniqueConstraint(name: 'uniq_enrollment_section', columns: ['student_enrollment_id', 'section_id'])]
class EnrollmentSection extends AbstractEntity
{
    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: StudentEnrollment::class, inversedBy: 'enrollmentSections')]
    #[ORM\JoinColumn(name: 'student_enrollment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private StudentEnrollment $studentEnrollment;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: Section::class)]
    #[ORM\JoinColumn(name: 'section_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Section $section;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = EnrollmentSectionStatus::Active->value;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $joinedAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $leftAt = null;

    public function __construct()
    {
        parent::__construct();
    }

    public function getStudentEnrollment(): StudentEnrollment
    {
        return $this->studentEnrollment;
    }

    public function setStudentEnrollment(StudentEnrollment $studentEnrollment): self
    {
        $this->studentEnrollment = $studentEnrollment;

        return $this;
    }

    public function getSection(): Section
    {
        return $this->section;
    }

    public function setSection(Section $section): self
    {
        $this->section = $section;

        return $this;
    }

    public function getStatus(): EnrollmentSectionStatus
    {
        return EnrollmentSectionStatus::from($this->status);
    }

    public function setStatus(EnrollmentSectionStatus $status): self
    {
        $this->status = $status->value;

        return $this;
    }

    public function getJoinedAt(): ?\DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function setJoinedAt(?\DateTimeImmutable $joinedAt): self
    {
        $this->joinedAt = $joinedAt;

        return $this;
    }

    public function getLeftAt(): ?\DateTimeImmutable
    {
        return $this->leftAt;
    }

    public function setLeftAt(?\DateTimeImmutable $leftAt): self
    {
        $this->leftAt = $leftAt;

        return $this;
    }
}
