<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\LeaveBalanceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LeaveBalanceRepository::class)]
#[ORM\Table(name: 'leave_balances')]
#[ORM\UniqueConstraint(name: 'uniq_leave_balance_student_year_type', columns: ['student_id', 'academic_year_id', 'leave_type_id'])]
#[ORM\Index(name: 'idx_leave_balance_institute', fields: ['institute'])]
#[ORM\Index(name: 'idx_leave_balance_student', fields: ['student'])]
class LeaveBalance extends AbstractEntity
{
    public const OPERATION_INCREMENT = 'increment';
    public const OPERATION_DECREMENT = 'decrement';

    #[ORM\ManyToOne(targetEntity: Institute::class)]
    #[ORM\JoinColumn(name: 'institute_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Institute $institute = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\ManyToOne(targetEntity: AcademicYear::class)]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    #[ORM\ManyToOne(targetEntity: LeaveType::class)]
    #[ORM\JoinColumn(name: 'leave_type_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private LeaveType $leaveType;

    #[ORM\Column(type: Types::INTEGER)]
    private int $allocated = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $used = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $pending = 0;

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

    public function getLeaveType(): LeaveType
    {
        return $this->leaveType;
    }

    public function setLeaveType(LeaveType $leaveType): self
    {
        $this->leaveType = $leaveType;

        return $this;
    }

    public function getAllocated(): int
    {
        return $this->allocated;
    }

    public function setAllocated(int $allocated): self
    {
        $this->allocated = max(0, $allocated);

        return $this;
    }

    public function getUsed(): int
    {
        return $this->used;
    }

    public function setUsed(int $used): self
    {
        $this->used = max(0, $used);

        return $this;
    }

    public function getPending(): int
    {
        return $this->pending;
    }

    public function setPending(int $pending): self
    {
        $this->pending = max(0, $pending);

        return $this;
    }

    public function getRemaining(): int
    {
        return max(0, $this->allocated - $this->used - $this->pending);
    }

    public function addAllocated(int $days): self
    {
        $this->allocated += $days;

        return $this;
    }

    public function addUsed(int $days): self
    {
        $this->used += $days;

        return $this;
    }

    public function subtractUsed(int $days): self
    {
        $this->used = max(0, $this->used - $days);

        return $this;
    }

    public function addPending(int $days): self
    {
        $this->pending += $days;

        return $this;
    }

    public function subtractPending(int $days): self
    {
        $this->pending = max(0, $this->pending - $days);

        return $this;
    }

    public function increment(int $operation, int $days): self
    {
        if ($operation === self::OPERATION_INCREMENT) {
            $this->allocated += $days;
        }

        return $this;
    }
}
