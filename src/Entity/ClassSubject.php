<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClassSubjectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ClassSubjectRepository::class)]
#[ORM\Table(name: 'class_subjects')]
#[ORM\UniqueConstraint(name: 'uniq_class_subject', columns: ['class_id', 'subject_id'])]
class ClassSubject extends AbstractEntity
{
    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: AcademicClass::class, inversedBy: 'classSubjects')]
    #[ORM\JoinColumn(name: 'class_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicClass $academicClass;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: Subject::class, inversedBy: 'classSubjects')]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Subject $subject;

    public function __construct()
    {
        parent::__construct();
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

    public function getSubject(): Subject
    {
        return $this->subject;
    }

    public function setSubject(Subject $subject): self
    {
        $this->subject = $subject;

        return $this;
    }
}
