<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExamAnswerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExamAnswerRepository::class)]
#[ORM\Table(name: 'exam_answers')]
#[ORM\Index(name: 'idx_exam_answer_attempt', fields: ['examAttempt'])]
#[ORM\Index(name: 'idx_exam_answer_question', fields: ['question'])]
#[ORM\Index(name: 'idx_exam_answer_selected_option', fields: ['selectedOption'])]
#[ORM\UniqueConstraint(name: 'uniq_exam_answer_attempt_question', columns: ['exam_attempt_id', 'question_id'])]
class ExamAnswer extends AbstractEntity
{
    #[ORM\ManyToOne(targetEntity: ExamAttempt::class, inversedBy: 'examAnswers')]
    #[ORM\JoinColumn(name: 'exam_attempt_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ExamAttempt $examAttempt;

    #[ORM\ManyToOne(targetEntity: Question::class)]
    #[ORM\JoinColumn(name: 'question_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Question $question;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $answerText = null;

    #[ORM\ManyToOne(targetEntity: QuestionOption::class)]
    #[ORM\JoinColumn(name: 'selected_option_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?QuestionOption $selectedOption = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $marksObtained = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: false, options: ['default' => true])]
    private bool $requiresManualEvaluation = true;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $isCorrect = null;

    #[ORM\OneToMany(mappedBy: 'examAnswer', targetEntity: ExamAnswerOption::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $selectedOptions;

    public function __construct()
    {
        parent::__construct();
        $this->selectedOptions = new ArrayCollection();
    }

    public function getExamAttempt(): ExamAttempt
    {
        return $this->examAttempt;
    }

    public function setExamAttempt(ExamAttempt $examAttempt): self
    {
        $this->examAttempt = $examAttempt;

        return $this;
    }

    public function getQuestion(): Question
    {
        return $this->question;
    }

    public function setQuestion(Question $question): self
    {
        $this->question = $question;

        return $this;
    }

    public function getAnswerText(): ?string
    {
        return $this->answerText;
    }

    public function setAnswerText(?string $answerText): self
    {
        $this->answerText = $answerText;

        return $this;
    }

    public function getSelectedOption(): ?QuestionOption
    {
        return $this->selectedOption;
    }

    public function setSelectedOption(?QuestionOption $selectedOption): self
    {
        $this->selectedOption = $selectedOption;

        return $this;
    }

    /**
     * @return Collection<int, ExamAnswerOption>
     */
    public function getSelectedOptions(): Collection
    {
        return $this->selectedOptions;
    }

    public function addSelectedOption(ExamAnswerOption $selectedOption): self
    {
        if (!$this->selectedOptions->contains($selectedOption)) {
            $this->selectedOptions->add($selectedOption);
            $selectedOption->setExamAnswer($this);
        }

        return $this;
    }

    public function removeSelectedOption(ExamAnswerOption $selectedOption): self
    {
        if ($this->selectedOptions->removeElement($selectedOption)) {
            if ($selectedOption->getExamAnswer() === $this) {
                $selectedOption->setExamAnswer(null);
            }
        }

        return $this;
    }

    public function clearSelectedOptions(): self
    {
        $this->selectedOptions->clear();

        return $this;
    }

    public function getMarksObtained(): ?int
    {
        return $this->marksObtained;
    }

    public function setMarksObtained(?int $marksObtained): self
    {
        $this->marksObtained = $marksObtained;

        return $this;
    }

    public function isRequiresManualEvaluation(): bool
    {
        return $this->requiresManualEvaluation;
    }

    public function setRequiresManualEvaluation(bool $requiresManualEvaluation): self
    {
        $this->requiresManualEvaluation = $requiresManualEvaluation;

        return $this;
    }

    public function getIsCorrect(): ?bool
    {
        return $this->isCorrect;
    }

    public function setIsCorrect(?bool $isCorrect): self
    {
        $this->isCorrect = $isCorrect;

        return $this;
    }
}
