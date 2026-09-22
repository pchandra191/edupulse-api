<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\BloodGroup;
use App\Enum\MaritalStatus;
use App\Enum\UserGender;
use App\Repository\UserProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserProfileRepository::class)]
#[ORM\Table(name: 'user_profiles')]
#[ORM\Index(name: 'idx_user_profile_user', fields: ['userId'])]
#[ORM\UniqueConstraint(name: 'uniq_user_profile_user', columns: ['user_id'])]
class UserProfile extends AbstractEntity
{
    #[ORM\Column(type: 'uuid')]
    private ?Uuid $userId = null;

    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $middleName = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateOfBirth = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $gender = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $bloodGroup = null;

    #[Assert\Length(max: 20)]
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $mobile = null;

    #[Assert\Length(max: 20)]
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $alternateMobile = null;

    #[Assert\Length(max: 500)]
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $profilePhotoReference = null;

    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $nationality = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $maritalStatus = null;

    #[Assert\Length(max: 200)]
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $emergencyContactName = null;

    #[Assert\Length(max: 20)]
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $emergencyContactPhone = null;

    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $emergencyContactRelationship = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $academicYear = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $academicClass = null;

    #[Assert\Length(max: 50)]
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $section = null;

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

    public function getMiddleName(): ?string
    {
        return $this->middleName;
    }

    public function setMiddleName(?string $middleName): self
    {
        $this->middleName = $middleName !== null ? trim($middleName) : null;

        return $this;
    }

    public function getDateOfBirth(): ?\DateTimeImmutable
    {
        return $this->dateOfBirth;
    }

    public function setDateOfBirth(?\DateTimeImmutable $dateOfBirth): self
    {
        $this->dateOfBirth = $dateOfBirth;

        return $this;
    }

    public function getGender(): ?UserGender
    {
        if (null === $this->gender) {
            return null;
        }

        try {
            return UserGender::from($this->gender);
        } catch (\ValueError) {
            return null;
        }
    }

    public function setGender(?UserGender $gender): self
    {
        $this->gender = $gender?->value;

        return $this;
    }

    public function getBloodGroup(): ?BloodGroup
    {
        if (null === $this->bloodGroup) {
            return null;
        }

        try {
            return BloodGroup::from($this->bloodGroup);
        } catch (\ValueError) {
            return null;
        }
    }

    public function setBloodGroup(?BloodGroup $bloodGroup): self
    {
        $this->bloodGroup = $bloodGroup?->value;

        return $this;
    }

    public function getMobile(): ?string
    {
        return $this->mobile;
    }

    public function setMobile(?string $mobile): self
    {
        $this->mobile = $mobile !== null ? trim($mobile) : null;

        return $this;
    }

    public function getAlternateMobile(): ?string
    {
        return $this->alternateMobile;
    }

    public function setAlternateMobile(?string $alternateMobile): self
    {
        $this->alternateMobile = $alternateMobile !== null ? trim($alternateMobile) : null;

        return $this;
    }

    public function getProfilePhotoReference(): ?string
    {
        return $this->profilePhotoReference;
    }

    public function setProfilePhotoReference(?string $profilePhotoReference): self
    {
        $this->profilePhotoReference = $profilePhotoReference !== null ? trim($profilePhotoReference) : null;

        return $this;
    }

    public function getNationality(): ?string
    {
        return $this->nationality;
    }

    public function setNationality(?string $nationality): self
    {
        $this->nationality = $nationality !== null ? trim($nationality) : null;

        return $this;
    }

    public function getMaritalStatus(): ?MaritalStatus
    {
        if ($this->maritalStatus === null) {
            return null;
        }

        try {
            return MaritalStatus::from($this->maritalStatus);
        } catch (\ValueError) {
            return null;
        }
    }

    public function setMaritalStatus(?MaritalStatus $maritalStatus): self
    {
        $this->maritalStatus = $maritalStatus?->value;

        return $this;
    }

    public function getEmergencyContactName(): ?string
    {
        return $this->emergencyContactName;
    }

    public function setEmergencyContactName(?string $emergencyContactName): self
    {
        $this->emergencyContactName = $emergencyContactName !== null ? trim($emergencyContactName) : null;

        return $this;
    }

    public function getEmergencyContactPhone(): ?string
    {
        return $this->emergencyContactPhone;
    }

    public function setEmergencyContactPhone(?string $emergencyContactPhone): self
    {
        $this->emergencyContactPhone = $emergencyContactPhone !== null ? trim($emergencyContactPhone) : null;

        return $this;
    }

    public function getEmergencyContactRelationship(): ?string
    {
        return $this->emergencyContactRelationship;
    }

    public function setEmergencyContactRelationship(?string $emergencyContactRelationship): self
    {
        $this->emergencyContactRelationship = $emergencyContactRelationship !== null ? trim($emergencyContactRelationship) : null;

        return $this;
    }

    public function getAcademicYear(): ?string
    {
        return $this->academicYear;
    }

    public function setAcademicYear(?string $academicYear): self
    {
        $this->academicYear = $academicYear !== null ? trim($academicYear) : null;

        return $this;
    }

    public function getAcademicClass(): ?string
    {
        return $this->academicClass;
    }

    public function setAcademicClass(?string $academicClass): self
    {
        $this->academicClass = $academicClass !== null ? trim($academicClass) : null;

        return $this;
    }

    public function getSection(): ?string
    {
        return $this->section;
    }

    public function setSection(?string $section): self
    {
        $this->section = $section !== null ? trim($section) : null;

        return $this;
    }
}
