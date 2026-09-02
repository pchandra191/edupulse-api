<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\QuestionType;
use App\Repository\QuestionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: QuestionRepository::class)]
#[ORM\Table(name: 'questions')]
#[ORM\Index(name: 'idx_question_paper', fields: ['questionPaper'])]
#[ORM\Index(name: 'idx_question_type', fields: ['type'])]
#[ORM\Index(name: 'idx_question_display_order', fields: ['questionPaper', 'displayOrder'])]
class Question extends AbstractEntity
{
    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: QuestionPaper::class, inversedBy: 'questions')]
    #[ORM\JoinColumn(name: 'question_paper_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private QuestionPaper $questionPaper;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['MCQ', 'DESCRIPTIVE'])]
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $type = QuestionType::MCQ->value;

    #[Assert\NotBlank]
    #[ORM\Column(type: Types::TEXT)]
    private string $questionText;

    #[Assert\NotBlank]
    #[Assert\Positive]
    #[ORM\Column(type: Types::INTEGER)]
    private int $marks;

    #[Assert\NotBlank]
    #[ORM\Column(type: Types::INTEGER)]
    private int $displayOrder;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $image = null;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $imageFileReference = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $referenceAnswer = null;

    #[ORM\OneToMany(mappedBy: 'question', targetEntity: QuestionOption::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $options;

    public function __construct()
    {
        parent::__construct();
        $this->options = new ArrayCollection();
    }

    public function getQuestionPaper(): QuestionPaper
    {
        return $this->questionPaper;
    }

    public function setQuestionPaper(QuestionPaper $questionPaper): self
    {
        $this->questionPaper = $questionPaper;

        return $this;
    }

    public function getType(): QuestionType
    {
        return QuestionType::from($this->type);
    }

    public function setType(QuestionType $type): self
    {
        $this->type = $type->value;

        return $this;
    }

    public function getQuestionText(): string
    {
        return $this->questionText;
    }

    public function setQuestionText(string $questionText): self
    {
        $this->questionText = $questionText;

        return $this;
    }

    public function getMarks(): int
    {
        return $this->marks;
    }

    public function setMarks(int $marks): self
    {
        $this->marks = $marks;

        return $this;
    }

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): self
    {
        $this->displayOrder = $displayOrder;

        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): self
    {
        $this->image = $image !== null ? trim($image) !== '' ? trim($image) : null : null;

        return $this;
    }

    public function getImageFileReference(): ?string
    {
        return $this->imageFileReference;
    }

    public function setImageFileReference(?string $imageFileReference): self
    {
        $this->imageFileReference = $imageFileReference !== null ? trim($imageFileReference) !== '' ? trim($imageFileReference) : null : null;

        return $this;
    }

    public function getImageSource(): ?string
    {
        if ($this->imageFileReference !== null) {
            return 'UPLOAD';
        }

        if ($this->image !== null) {
            return 'URL';
        }

        return null;
    }

    public function getReferenceAnswer(): ?string
    {
        return $this->referenceAnswer;
    }

    public function setReferenceAnswer(?string $referenceAnswer): self
    {
        $this->referenceAnswer = $referenceAnswer;

        return $this;
    }

    public function getOptions(): Collection
    {
        return $this->options;
    }

    public function addOption(QuestionOption $option): self
    {
        if (!$this->options->contains($option)) {
            $this->options[] = $option;
            $option->setQuestion($this);
        }

        return $this;
    }

    public function removeOption(QuestionOption $option): self
    {
        if ($this->options->removeElement($option)) {
            if ($option->getQuestion() === $this) {
                $option->setQuestion(new Question());
            }
        }

        return $this;
    }

    public function isMCQ(): bool
    {
        return $this->type === QuestionType::MCQ->value;
    }

    public function isDescriptive(): bool
    {
        return $this->type === QuestionType::Descriptive->value;
    }
}
