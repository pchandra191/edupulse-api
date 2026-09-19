<?php
declare(strict_types=1);

namespace App\Entity;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\Institute;
use App\Entity\Section;
use App\Repository\HolidayRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HolidayRepository::class)]
#[ORM\Table(name: 'holidays')]
#[ORM\UniqueConstraint(name: 'uniq_holiday_institute_date', columns: ['institute_id', 'holiday_date'])]
#[ORM\Index(name: 'idx_holiday_institute', fields: ['institute'])]
#[ORM\Index(name: 'idx_holiday_academic_year', fields: ['academicYear'])]
#[ORM\Index(name: 'idx_holiday_date', fields: ['holidayDate'])]
class Holiday extends AbstractEntity
{
    #[ORM\ManyToOne(targetEntity: Institute::class)]
    #[ORM\JoinColumn(name: 'institute_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Institute $institute = null;

    #[ORM\ManyToOne(targetEntity: AcademicYear::class)]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AcademicYear $academicYear = null;

    #[ORM\ManyToOne(targetEntity: AcademicClass::class)]
    #[ORM\JoinColumn(name: 'academic_class_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AcademicClass $academicClass = null;

    #[ORM\ManyToOne(targetEntity: Section::class)]
    #[ORM\JoinColumn(name: 'section_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Section $section = null;

    #[ORM\Column(name: 'holiday_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $holidayDate;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = true;

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

    public function getHolidayDate(): \DateTimeImmutable
    {
        return $this->holidayDate;
    }

    public function setHolidayDate(\DateTimeImmutable $holidayDate): self
    {
        $this->holidayDate = $holidayDate;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }
}
