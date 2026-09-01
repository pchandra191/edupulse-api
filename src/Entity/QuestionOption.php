<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\QuestionOptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: QuestionOptionRepository::class)]
#[ORM\Table(name: 'question_options')]
#[ORM\Index(name: 'idx_question_option_question', fields: ['question'])]
#[ORM\Index(name: 'idx_question_option_display_order', fields: ['question', 'displayOrder'])]
class QuestionOption extends AbstractEntity
{
    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: Question::class, inversedBy: 'options')]
    #[ORM\JoinColumn(name: 'question_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Question $question;

    #[Assert\NotBlank]
    #[ORM\Column(type: Types::TEXT)]
    private string $optionText;

    #[Assert\NotBlank]
    #[ORM\Column(type: Types::INTEGER)]
    private int $displayOrder;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isCorrect = false;

    public function getQuestion(): Question
    {
        return $this->question;
    }

    public function setQuestion(Question $question): self
    {
        $this->question = $question;

        return $this;
    }

    public function getOptionText(): string
    {
        return $this->optionText;
    }

    public function setOptionText(string $optionText): self
    {
        $this->optionText = $optionText;

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

    public function isCorrect(): bool
    {
        return $this->isCorrect;
    }

    public function setIsCorrect(bool $isCorrect): self
    {
        $this->isCorrect = $isCorrect;

        return $this;
    }
}
