<?php
declare(strict_types=1);

namespace App\Entity;

use App\Entity\AcademicClass;
use App\Enum\AcademicYearStatus;
use App\Repository\AcademicYearRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AcademicYearRepository::class)]
#[ORM\Table(name: 'academic_years')]
#[ORM\Index(name: 'idx_academic_year_status', fields: ['status'])]
#[ORM\UniqueConstraint(name: 'uniq_academic_year_institute_code', columns: ['institute_id', 'code'])]
class AcademicYear extends AbstractEntity
{
    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: Institute::class, inversedBy: 'academicYears')]
    #[ORM\JoinColumn(name: 'institute_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institute $institute;

    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    #[ORM\Column(length: 50)]
    private string $name;

    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    #[ORM\Column(length: 50)]
    private string $code;

    #[Assert\NotBlank]
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startDate;

    #[Assert\NotBlank]
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endDate;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = false;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = AcademicYearStatus::Inactive->value;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\OneToMany(mappedBy: 'academicYear', targetEntity: AcademicClass::class, cascade: ['persist'], orphanRemoval: false)]
    private Collection $classes;

    public function __construct()
    {
        parent::__construct();
        $this->classes = new ArrayCollection();
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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getStatus(): AcademicYearStatus
    {
        return AcademicYearStatus::from($this->status);
    }

    public function setStatus(AcademicYearStatus $status): self
    {
        $this->status = $status->value;

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

    public function getClasses(): Collection
    {
        return $this->classes;
    }

    public function addClass(AcademicClass $class): self
    {
        if (!$this->classes->contains($class)) {
            $this->classes[] = $class;
            $class->setAcademicYear($this);
        }

        return $this;
    }

    public function removeClass(AcademicClass $class): self
    {
        if ($this->classes->removeElement($class)) {
            if ($class->getAcademicYear() === $this) {
                $class->setAcademicYear(new AcademicYear());
            }
        }

        return $this;
    }
}
