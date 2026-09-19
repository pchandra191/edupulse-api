<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\AttendanceStatus;
use App\Repository\AttendanceRecordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AttendanceRecordRepository::class)]
#[ORM\Table(name: 'attendance_records')]
#[ORM\Index(name: 'idx_attendance_date', fields: ['attendanceDate'])]
#[ORM\Index(name: 'idx_attendance_student', fields: ['student'])]
#[ORM\Index(name: 'idx_attendance_institute', fields: ['institute'])]
#[ORM\UniqueConstraint(
    name: 'uniq_attendance_student_date_class',
    columns: ['student_id', 'attendance_date', 'academic_class_id', 'section_id']
)]
class AttendanceRecord extends AbstractEntity
{
    public const SOURCE_MANUAL = 'MANUAL';
    public const SOURCE_BULK = 'BULK';
    public const SOURCE_LEAVE_APPROVAL = 'LEAVE_APPROVAL';
    public const SOURCE_CORRECTION = 'CORRECTION';
    public const SOURCE_SYSTEM = 'SYSTEM';

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
    #[ORM\JoinColumn(name: 'academic_class_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicClass $academicClass;

    #[ORM\ManyToOne(targetEntity: Section::class)]
    #[ORM\JoinColumn(name: 'section_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Section $section = null;

    #[ORM\ManyToOne(targetEntity: Subject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Subject $subject = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, name: 'attendance_date')]
    private \DateTimeImmutable $attendanceDate;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = AttendanceStatus::Present->value;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'marked_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $markedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, name: 'marked_at', nullable: true)]
    private ?\DateTimeImmutable $markedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $remarks = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $source = self::SOURCE_MANUAL;

    #[ORM\ManyToOne(targetEntity: LeaveRequest::class)]
    #[ORM\JoinColumn(name: 'leave_request_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?LeaveRequest $leaveRequest = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 8, nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'decimal', precision: 11, scale: 8, nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?float $accuracyMeters = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, name: 'location_captured_at', nullable: true)]
    private ?\DateTimeImmutable $locationCapturedAt = null;

    public function __construct()
    {
        parent::__construct();
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

    public function getAttendanceDate(): \DateTimeImmutable
    {
        return $this->attendanceDate;
    }

    public function setAttendanceDate(\DateTimeImmutable $attendanceDate): self
    {
        $this->attendanceDate = $attendanceDate;

        return $this;
    }

    public function getStatus(): AttendanceStatus
    {
        return AttendanceStatus::from($this->status);
    }

    public function setStatus(AttendanceStatus $status): self
    {
        $this->status = $status->value;

        return $this;
    }

    public function getStatusValue(): string
    {
        return $this->status;
    }

    public function getMarkedBy(): ?User
    {
        return $this->markedBy;
    }

    public function setMarkedBy(?User $markedBy): self
    {
        $this->markedBy = $markedBy;

        return $this;
    }

    public function getMarkedAt(): ?\DateTimeImmutable
    {
        return $this->markedAt;
    }

    public function setMarkedAt(?\DateTimeImmutable $markedAt): self
    {
        $this->markedAt = $markedAt;

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

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getLeaveRequest(): ?LeaveRequest
    {
        return $this->leaveRequest;
    }

    public function setLeaveRequest(?LeaveRequest $leaveRequest): self
    {
        $this->leaveRequest = $leaveRequest;

        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): self
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): self
    {
        $this->longitude = $longitude;

        return $this;
    }

    public function getAccuracyMeters(): ?float
    {
        return $this->accuracyMeters;
    }

    public function setAccuracyMeters(?float $accuracyMeters): self
    {
        $this->accuracyMeters = $accuracyMeters;

        return $this;
    }

    public function getLocationCapturedAt(): ?\DateTimeImmutable
    {
        return $this->locationCapturedAt;
    }

    public function setLocationCapturedAt(?\DateTimeImmutable $locationCapturedAt): self
    {
        $this->locationCapturedAt = $locationCapturedAt;

        return $this;
    }

    public function isPresent(): bool
    {
        return in_array($this->status, [AttendanceStatus::Present->value, AttendanceStatus::Late->value, AttendanceStatus::HalfDay->value], true);
    }
}
