<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\LeaveRequestStatus;
use App\Repository\LeaveRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LeaveRequestRepository::class)]
#[ORM\Table(name: 'leave_requests')]
#[ORM\Index(name: 'idx_leave_request_student', fields: ['student'])]
#[ORM\Index(name: 'idx_leave_request_status', fields: ['status'])]
#[ORM\Index(name: 'idx_leave_request_dates', fields: ['startDate', 'endDate'])]
#[ORM\Index(name: 'idx_leave_request_institute', fields: ['institute'])]
class LeaveRequest extends AbstractEntity
{
    public const APPROVAL_REQUIRED = 'required';

    #[ORM\ManyToOne(targetEntity: Institute::class)]
    #[ORM\JoinColumn(name: 'institute_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Institute $institute = null;

    #[ORM\ManyToOne(targetEntity: AcademicYear::class)]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\ManyToOne(targetEntity: AcademicClass::class)]
    #[ORM\JoinColumn(name: 'academic_class_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AcademicClass $academicClass = null;

    #[ORM\ManyToOne(targetEntity: Section::class)]
    #[ORM\JoinColumn(name: 'section_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Section $section = null;

    #[ORM\ManyToOne(targetEntity: LeaveType::class)]
    #[ORM\JoinColumn(name: 'leave_type_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private LeaveType $leaveType;

    #[ORM\Column(name: 'start_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(name: 'end_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endDate;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $attachment = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = LeaveRequestStatus::Pending->value;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'approved_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $approvedBy = null;

    #[ORM\Column(name: 'approval_remarks', type: Types::TEXT, nullable: true)]
    private ?string $approvalRemarks = null;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $submittedAt;

    #[ORM\Column(name: 'approved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(name: 'cancelled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(name: 'cancellation_reason', type: Types::TEXT, nullable: true)]
    private ?string $cancellationReason = null;

    public function __construct()
    {
        parent::__construct();
        $this->submittedAt = new \DateTimeImmutable();
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

    public function getLeaveType(): LeaveType
    {
        return $this->leaveType;
    }

    public function setLeaveType(LeaveType $leaveType): self
    {
        $this->leaveType = $leaveType;

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

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): self
    {
        $this->reason = trim($reason);

        return $this;
    }

    public function getAttachment(): ?string
    {
        return $this->attachment;
    }

    public function setAttachment(?string $attachment): self
    {
        $this->attachment = $attachment;

        return $this;
    }

    public function getStatus(): LeaveRequestStatus
    {
        return LeaveRequestStatus::from($this->status);
    }

    public function getStatusValue(): string
    {
        return $this->status;
    }

    public function setStatus(LeaveRequestStatus $status): self
    {
        $this->status = $status->value;

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

    public function getApprovalRemarks(): ?string
    {
        return $this->approvalRemarks;
    }

    public function setApprovalRemarks(?string $approvalRemarks): self
    {
        $this->approvalRemarks = $approvalRemarks !== null ? trim($approvalRemarks) : null;

        return $this;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(\DateTimeImmutable $submittedAt): self
    {
        $this->submittedAt = $submittedAt;

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

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): self
    {
        $this->cancelledAt = $cancelledAt;

        return $this;
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function setCancellationReason(?string $cancellationReason): self
    {
        $this->cancellationReason = $cancellationReason !== null ? trim($cancellationReason) : null;

        return $this;
    }

    public function getTotalDays(): int
    {
        return (int) $this->startDate->diff($this->endDate)->days + 1;
    }

    public function isApproved(): bool
    {
        return $this->status === LeaveRequestStatus::Approved->value;
    }

    public function isPending(): bool
    {
        return $this->status === LeaveRequestStatus::Pending->value;
    }
}
