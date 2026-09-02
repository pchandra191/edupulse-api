<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExamAnswerOptionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExamAnswerOptionRepository::class)]
#[ORM\Table(name: 'exam_answer_options')]
#[ORM\Index(name: 'idx_exam_answer_option_answer', fields: ['examAnswer'])]
#[ORM\Index(name: 'idx_exam_answer_option_option', fields: ['questionOption'])]
#[ORM\UniqueConstraint(name: 'uniq_exam_answer_option_answer_option', columns: ['exam_answer_id', 'question_option_id'])]
class ExamAnswerOption extends AbstractEntity
{
    #[ORM\ManyToOne(targetEntity: ExamAnswer::class, inversedBy: 'selectedOptions')]
    #[ORM\JoinColumn(name: 'exam_answer_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ExamAnswer $examAnswer;

    #[ORM\ManyToOne(targetEntity: QuestionOption::class)]
    #[ORM\JoinColumn(name: 'question_option_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private QuestionOption $questionOption;

    public function getExamAnswer(): ExamAnswer
    {
        return $this->examAnswer;
    }

    public function setExamAnswer(ExamAnswer $examAnswer): self
    {
        $this->examAnswer = $examAnswer;

        return $this;
    }

    public function getQuestionOption(): QuestionOption
    {
        return $this->questionOption;
    }

    public function setQuestionOption(QuestionOption $questionOption): self
    {
        $this->questionOption = $questionOption;

        return $this;
    }
}
