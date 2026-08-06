<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\ClassStatus;
use App\Repository\AcademicClassRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AcademicClassRepository::class)]
#[ORM\Table(name: 'classes')]
#[ORM\Index(name: 'idx_class_status', fields: ['status'])]
#[ORM\UniqueConstraint(name: 'uniq_class_institute_code', columns: ['institute_id', 'code'])]
class AcademicClass extends AbstractEntity
{
    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: Institute::class, inversedBy: 'classes')]
    #[ORM\JoinColumn(name: 'institute_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institute $institute;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: AcademicYear::class, inversedBy: 'classes')]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

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

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = ClassStatus::Active->value;

    public function __construct()
    {
        parent::__construct();
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

    public function getStatus(): ClassStatus
    {
        return ClassStatus::from($this->status);
    }

    public function setStatus(ClassStatus $status): self
    {
        $this->status = $status->value;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === ClassStatus::Active->value;
    }
}
