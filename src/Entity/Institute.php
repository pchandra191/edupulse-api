<?php
declare(strict_types=1);

namespace App\Entity;

use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\Subject;
use App\Enum\Currency;
use App\Enum\InstituteStatus;
use App\Enum\Timezone;
use App\Repository\InstituteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: InstituteRepository::class)]
#[ORM\Table(name: 'institutes')]
#[ORM\Index(name: 'idx_institute_code', fields: ['code'])]
#[ORM\Index(name: 'idx_institute_email', fields: ['email'])]
#[ORM\Index(name: 'idx_institute_status', fields: ['status'])]
#[UniqueEntity(fields: ['code'])]
#[UniqueEntity(fields: ['email'])]
class Institute extends AbstractEntity
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $name;

    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    #[ORM\Column(length: 50, unique: true)]
    private string $code;

    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[Assert\Length(max: 20)]
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;

    #[Assert\Length(max: 255)]
    #[Assert\Url]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logo = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $addressLine1 = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $addressLine2 = null;

    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $state = null;

    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $country = null;

    #[Assert\Length(max: 20)]
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $postalCode = null;

    #[Assert\Length(max: 100)]
    #[Assert\Choice(choices: ['Asia/Kolkata', 'America/New_York', 'Europe/Berlin', 'Europe/London', 'Asia/Dubai', 'Asia/Singapore'])]
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $timezone = null;

    #[Assert\Length(max: 3)]
    #[Assert\Choice(choices: ['INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD'])]
    #[ORM\Column(type: Types::STRING, length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = InstituteStatus::Active->value;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $onboardingCompletedAt = null;

    #[ORM\OneToMany(mappedBy: 'institute', targetEntity: User::class, cascade: ['persist'], orphanRemoval: false)]
    private Collection $users;

    #[ORM\OneToMany(mappedBy: 'institute', targetEntity: AcademicYear::class, cascade: ['persist'], orphanRemoval: false)]
    private Collection $academicYears;

    #[ORM\OneToMany(mappedBy: 'institute', targetEntity: AcademicClass::class, cascade: ['persist'], orphanRemoval: false)]
    private Collection $classes;

    #[ORM\OneToMany(mappedBy: 'institute', targetEntity: Subject::class, cascade: ['persist'], orphanRemoval: false)]
    private Collection $subjects;

    #[ORM\OneToMany(mappedBy: 'institute', targetEntity: Examination::class, cascade: ['persist'], orphanRemoval: false)]
    private Collection $examinations;

    #[ORM\OneToMany(mappedBy: 'institute', targetEntity: QuestionPaper::class, cascade: ['persist'], orphanRemoval: false)]
    private Collection $questionPapers;

    public function __construct()
    {
        parent::__construct();
        $this->users = new ArrayCollection();
        $this->academicYears = new ArrayCollection();
        $this->classes = new ArrayCollection();
        $this->subjects = new ArrayCollection();
        $this->examinations = new ArrayCollection();
        $this->questionPapers = new ArrayCollection();
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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone !== null ? trim($phone) : null;

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): self
    {
        $this->website = $website !== null ? trim($website) : null;

        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): self
    {
        $this->logo = $logo !== null ? trim($logo) : null;

        return $this;
    }

    public function getAddressLine1(): ?string
    {
        return $this->addressLine1;
    }

    public function setAddressLine1(?string $addressLine1): self
    {
        $this->addressLine1 = $addressLine1 !== null ? trim($addressLine1) : null;

        return $this;
    }

    public function getAddressLine2(): ?string
    {
        return $this->addressLine2;
    }

    public function setAddressLine2(?string $addressLine2): self
    {
        $this->addressLine2 = $addressLine2 !== null ? trim($addressLine2) : null;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): self
    {
        $this->city = $city !== null ? trim($city) : null;

        return $this;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function setState(?string $state): self
    {
        $this->state = $state !== null ? trim($state) : null;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): self
    {
        $this->country = $country !== null ? trim($country) : null;

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): self
    {
        $this->postalCode = $postalCode !== null ? trim($postalCode) : null;

        return $this;
    }

    public function getTimezone(): ?Timezone
    {
        return null !== $this->timezone ? Timezone::from($this->timezone) : null;
    }

    public function setTimezone(?Timezone $timezone): self
    {
        $this->timezone = $timezone?->value;

        return $this;
    }

    public function getCurrency(): ?Currency
    {
        return null !== $this->currency ? Currency::from($this->currency) : null;
    }

    public function setCurrency(?Currency $currency): self
    {
        $this->currency = $currency?->value;

        return $this;
    }

    public function getStatus(): InstituteStatus
    {
        return InstituteStatus::from($this->status);
    }

    public function setStatus(InstituteStatus $status): self
    {
        $this->status = $status->value;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === InstituteStatus::Active->value;
    }

    public function getOnboardingCompletedAt(): ?\DateTimeInterface
    {
        return $this->onboardingCompletedAt;
    }

    public function setOnboardingCompletedAt(?\DateTimeInterface $onboardingCompletedAt): self
    {
        $this->onboardingCompletedAt = $onboardingCompletedAt;

        return $this;
    }

    public function isOnboardingCompleted(): bool
    {
        return null !== $this->onboardingCompletedAt;
    }

    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): self
    {
        if (!$this->users->contains($user)) {
            $this->users[] = $user;
            $user->setInstitute($this);
        }

        return $this;
    }

    public function removeUser(User $user): self
    {
        if ($this->users->removeElement($user)) {
            if ($user->getInstitute() === $this) {
                $user->setInstitute(null);
            }
        }

        return $this;
    }

    public function getAcademicYears(): Collection
    {
        return $this->academicYears;
    }

    public function addAcademicYear(AcademicYear $academicYear): self
    {
        if (!$this->academicYears->contains($academicYear)) {
            $this->academicYears[] = $academicYear;
            $academicYear->setInstitute($this);
        }

        return $this;
    }

    public function removeAcademicYear(AcademicYear $academicYear): self
    {
        if ($this->academicYears->removeElement($academicYear)) {
            if ($academicYear->getInstitute() === $this) {
                $academicYear->setInstitute(new Institute());
            }
        }

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
            $class->setInstitute($this);
        }

        return $this;
    }

    public function removeClass(AcademicClass $class): self
    {
        if ($this->classes->removeElement($class)) {
            if ($class->getInstitute() === $this) {
                $class->setInstitute(new Institute());
            }
        }

        return $this;
    }

    public function getSubjects(): Collection
    {
        return $this->subjects;
    }

    public function addSubject(Subject $subject): self
    {
        if (!$this->subjects->contains($subject)) {
            $this->subjects[] = $subject;
            $subject->setInstitute($this);
        }

        return $this;
    }

    public function removeSubject(Subject $subject): self
    {
        if ($this->subjects->removeElement($subject)) {
            if ($subject->getInstitute() === $this) {
                $subject->setInstitute(new Institute());
            }
        }

        return $this;
    }

    public function getExaminations(): Collection
    {
        return $this->examinations;
    }

    public function addExamination(Examination $examination): self
    {
        if (!$this->examinations->contains($examination)) {
            $this->examinations[] = $examination;
            $examination->setInstitute($this);
        }

        return $this;
    }

    public function removeExamination(Examination $examination): self
    {
        if ($this->examinations->removeElement($examination)) {
            if ($examination->getInstitute() === $this) {
                $examination->setInstitute(new Institute());
            }
        }

        return $this;
    }

    public function getQuestionPapers(): Collection
    {
        return $this->questionPapers;
    }

    public function addQuestionPaper(QuestionPaper $questionPaper): self
    {
        if (!$this->questionPapers->contains($questionPaper)) {
            $this->questionPapers[] = $questionPaper;
            $questionPaper->setInstitute($this);
        }

        return $this;
    }

    public function removeQuestionPaper(QuestionPaper $questionPaper): self
    {
        if ($this->questionPapers->removeElement($questionPaper)) {
            if ($questionPaper->getInstitute() === $this) {
                $questionPaper->setInstitute(new Institute());
            }
        }

        return $this;
    }
}
