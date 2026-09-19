<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\AttendanceCorrectionStatus;
use App\Repository\AttendanceCorrectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AttendanceCorrectionRepository::class)]
#[ORM\Table(name: 'attendance_corrections')]
#[ORM\Index(name: 'idx_correction_status', fields: ['status'])]
#[ORM\Index(name: 'idx_correction_attendance_record', fields: ['attendanceRecord'])]
#[ORM\Index(name: 'idx_correction_student', fields: ['student'])]
#[ORM\Index(name: 'idx_correction_institute', fields: ['institute'])]
class AttendanceCorrection extends AbstractEntity
{
    #[ORM\ManyToOne(targetEntity: Institute::class)]
    #[ORM\JoinColumn(name: 'institute_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Institute $institute = null;

    #[ORM\ManyToOne(targetEntity: AcademicYear::class)]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    #[ORM\ManyToOne(targetEntity: AttendanceRecord::class)]
    #[ORM\JoinColumn(name: 'attendance_record_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AttendanceRecord $attendanceRecord;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\ManyToOne(targetEntity: AcademicClass::class)]
    #[ORM\JoinColumn(name: 'academic_class_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AcademicClass $academicClass = null;

    #[ORM\ManyToOne(targetEntity: Section::class)]
    #[ORM\JoinColumn(name: 'section_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Section $section = null;

    #[ORM\Column(name: 'original_status', type: Types::STRING, length: 20)]
    private string $originalStatus;

    #[ORM\Column(name: 'corrected_status', type: Types::STRING, length: 20)]
    private string $correctedStatus;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requested_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $requestedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'approved_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $approvedBy = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = AttendanceCorrectionStatus::Pending->value;

    #[ORM\Column(name: 'requested_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(name: 'approved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $remarks = null;

    public function __construct()
    {
        parent::__construct();
        $this->requestedAt = new \DateTimeImmutable();
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

    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
    }

    public function setAcademicYear(AcademicYear $academicYear): self
    {
        $this->academicYear = $academicYear;

        return $this;
    }

    public function getAttendanceRecord(): AttendanceRecord
    {
        return $this->attendanceRecord;
    }

    public function setAttendanceRecord(AttendanceRecord $attendanceRecord): self
    {
        $this->attendanceRecord = $attendanceRecord;

        return $this;
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

    public function getAcademicClass(): ?AcademicClass
    {
        return $this->academicClass;
    }

    public function setAcademicClass(?AcademicClass $academicClass): self
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

    public function getOriginalStatus(): string
    {
        return $this->originalStatus;
    }

    public function setOriginalStatus(string $originalStatus): self
    {
        $this->originalStatus = $originalStatus;

        return $this;
    }

    public function getCorrectedStatus(): string
    {
        return $this->correctedStatus;
    }

    public function setCorrectedStatus(string $correctedStatus): self
    {
        $this->correctedStatus = $correctedStatus;

        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): self
    {
        $this->reason = trim($reason);

        return $this;
    }

    public function getRequestedBy(): ?User
    {
        return $this->requestedBy;
    }

    public function setRequestedBy(?User $requestedBy): self
    {
        $this->requestedBy = $requestedBy;

        return $this;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function setApprovedBy(?User $approvedBy): self
    {
        $this->approvedBy = $approvedBy;

        return $this;
    }

    public function getStatus(): AttendanceCorrectionStatus
    {
        return AttendanceCorrectionStatus::from($this->status);
    }

    public function getStatusValue(): string
    {
        return $this->status;
    }

    public function setStatus(AttendanceCorrectionStatus $status): self
    {
        $this->status = $status->value;

        return $this;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function setRequestedAt(\DateTimeImmutable $requestedAt): self
    {
        $this->requestedAt = $requestedAt;

        return $this;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeImmutable $approvedAt): self
    {
        $this->approvedAt = $approvedAt;

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

    public function isApproved(): bool
    {
        return $this->status === AttendanceCorrectionStatus::Approved->value;
    }

    public function isPending(): bool
    {
        return $this->status === AttendanceCorrectionStatus::Pending->value;
    }
}
