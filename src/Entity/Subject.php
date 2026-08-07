<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\SubjectStatus;
use App\Repository\SubjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SubjectRepository::class)]
#[ORM\Table(name: 'subjects')]
#[ORM\UniqueConstraint(name: 'uniq_subject_institute_code', columns: ['institute_id', 'code'])]
class Subject extends AbstractEntity
{
    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: Institute::class, inversedBy: 'subjects')]
    #[ORM\JoinColumn(name: 'institute_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institute $institute;

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
    private string $status = SubjectStatus::Active->value;

    #[ORM\OneToMany(mappedBy: 'subject', targetEntity: ClassSubject::class, cascade: ['persist'], orphanRemoval: false)]
    private Collection $classSubjects;

    public function __construct()
    {
        parent::__construct();
        $this->classSubjects = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description !== null ? trim($description) : null;

        return $this;
    }

    public function getStatus(): SubjectStatus
    {
        return SubjectStatus::from($this->status);
    }

    public function setStatus(SubjectStatus $status): self
    {
        $this->status = $status->value;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === SubjectStatus::Active->value;
    }

    public function getClassSubjects(): Collection
    {
        return $this->classSubjects;
    }
}
