<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\EducationLevel;
use App\Enum\EducationResult;
use App\Repository\UserEducationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserEducationRepository::class)]
#[ORM\Table(name: 'user_education')]
#[ORM\Index(name: 'idx_user_education_user', fields: ['userId'])]
#[ORM\Index(name: 'idx_user_education_level', fields: ['educationLevel'])]
class UserEducation extends AbstractEntity
{
    #[ORM\Column(type: 'uuid')]
    private ?Uuid $userId = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $institutionName;

    #[ORM\Column(length: 30)]
    private string $educationLevel = EducationLevel::Other->value;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $qualification;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $specialization = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $boardUniversity;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    #[Assert\Length(max: 50)]
    #[Assert\Choice(choices: ['PASS', 'FAIL', 'PENDING'])]
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $result = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $percentage = null;

    #[Assert\Length(max: 10)]
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $grade = null;

    public function __construct()
    {
        parent::__construct();
    }

    public function getUserId(): ?Uuid
    {
        return $this->userId;
    }

    public function setUserId(?Uuid $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function getInstitutionName(): string
    {
        return $this->institutionName;
    }

    public function setInstitutionName(string $institutionName): self
    {
        $this->institutionName = trim($institutionName);

        return $this;
    }

    public function getEducationLevel(): EducationLevel
    {
        return EducationLevel::from($this->educationLevel);
    }

    public function setEducationLevel(EducationLevel $educationLevel): self
    {
        $this->educationLevel = $educationLevel->value;

        return $this;
    }

    public function getQualification(): string
    {
        return $this->qualification;
    }

    public function setQualification(string $qualification): self
    {
        $this->qualification = trim($qualification);

        return $this;
    }

    public function getSpecialization(): ?string
    {
        return $this->specialization;
    }

    public function setSpecialization(?string $specialization): self
    {
        $this->specialization = $specialization !== null && $specialization !== '' ? trim($specialization) : null;

        return $this;
    }

    public function getBoardUniversity(): string
    {
        return $this->boardUniversity;
    }

    public function setBoardUniversity(string $boardUniversity): self
    {
        $this->boardUniversity = trim($boardUniversity);

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): self
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): self
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getResult(): ?EducationResult
    {
        if ($this->result === null) {
            return null;
        }

        return EducationResult::from($this->result);
    }

    public function setResult(?EducationResult $result): self
    {
        $this->result = $result?->value;

        return $this;
    }

    public function getPercentage(): ?string
    {
        return $this->percentage;
    }

    public function setPercentage(?string $percentage): self
    {
        $this->percentage = $percentage;

        return $this;
    }

    public function getGrade(): ?string
    {
        return $this->grade;
    }

    public function setGrade(?string $grade): self
    {
        $this->grade = $grade !== null && $grade !== '' ? trim($grade) : null;

        return $this;
    }
}
