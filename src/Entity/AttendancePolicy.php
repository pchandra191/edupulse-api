<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\AttendancePolicyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AttendancePolicyRepository::class)]
#[ORM\Table(name: 'attendance_policies')]
#[ORM\UniqueConstraint(name: 'uniq_attendance_policy_institute_year', columns: ['institute_id', 'academic_year_id'])]
#[ORM\Index(name: 'idx_attendance_policy_institute', fields: ['institute'])]
class AttendancePolicy extends AbstractEntity
{
    #[ORM\ManyToOne(targetEntity: Institute::class)]
    #[ORM\JoinColumn(name: 'institute_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Institute $institute = null;

    #[ORM\ManyToOne(targetEntity: AcademicYear::class)]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AcademicYear $academicYear = null;

    #[ORM\Column(name: 'minimum_attendance_percentage', type: Types::INTEGER)]
    private int $minimumAttendancePercentage = 75;

    #[ORM\Column(name: 'late_threshold_count', type: Types::INTEGER, nullable: true)]
    private ?int $lateThresholdCount = null;

    #[ORM\Column(name: 'half_day_behavior', type: Types::STRING, length: 50, nullable: true)]
    private ?string $halfDayBehavior = null;

    #[ORM\Column(name: 'approved_leave_counts', type: Types::BOOLEAN)]
    private bool $approvedLeaveCounts = false;

    #[ORM\Column(name: 'working_days', type: Types::STRING, length: 50, nullable: true)]
    private ?string $workingDays = null;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN)]
    private bool $isActive = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

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

    public function getAcademicYear(): ?AcademicYear
    {
        return $this->academicYear;
    }

    public function setAcademicYear(?AcademicYear $academicYear): self
    {
        $this->academicYear = $academicYear;

        return $this;
    }

    public function getMinimumAttendancePercentage(): int
    {
        return $this->minimumAttendancePercentage;
    }

    public function setMinimumAttendancePercentage(int $minimumAttendancePercentage): self
    {
        $this->minimumAttendancePercentage = $minimumAttendancePercentage;

        return $this;
    }

    public function getLateThresholdCount(): ?int
    {
        return $this->lateThresholdCount;
    }

    public function setLateThresholdCount(?int $lateThresholdCount): self
    {
        $this->lateThresholdCount = $lateThresholdCount;

        return $this;
    }

    public function getHalfDayBehavior(): ?string
    {
        return $this->halfDayBehavior;
    }

    public function setHalfDayBehavior(?string $halfDayBehavior): self
    {
        $this->halfDayBehavior = $halfDayBehavior !== null ? trim($halfDayBehavior) : null;

        return $this;
    }

    public function isApprovedLeaveCounts(): bool
    {
        return $this->approvedLeaveCounts;
    }

    public function setApprovedLeaveCounts(bool $approvedLeaveCounts): self
    {
        $this->approvedLeaveCounts = $approvedLeaveCounts;

        return $this;
    }

    public function getWorkingDays(): ?string
    {
        return $this->workingDays;
    }

    public function setWorkingDays(?string $workingDays): self
    {
        $this->workingDays = $workingDays !== null ? trim($workingDays) : null;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

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
}
