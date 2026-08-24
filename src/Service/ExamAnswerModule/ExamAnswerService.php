<?php
declare(strict_types=1);

namespace App\Service\ExamAnswerModule;

use App\DTO\ExamAnswerModule\ExamAnswerResponseDTO;
use App\DTO\ExamAnswerModule\SaveExamAnswerRequestDTO;
use App\Entity\ExamAnswer;
use App\Entity\ExamAttempt;
use App\Entity\ExamAnswerOption;
use App\Entity\Question;
use App\Entity\QuestionOption;
use App\Enum\QuestionType;
use App\Exception\AuthenticationException;
use App\Repository\ExamAnswerRepository;
use App\Repository\ExamAttemptRepository;
use App\Repository\QuestionRepository;
use App\Repository\QuestionOptionRepository;
use DateTimeInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\Registry as WorkflowRegistry;

readonly class ExamAnswerService
{
    public function __construct(
        private Security $security,
        private ExamAttemptRepository $examAttemptRepository,
        private ExamAnswerRepository $examAnswerRepository,
        private QuestionRepository $questionRepository,
        private QuestionOptionRepository $questionOptionRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private WorkflowRegistry $workflowRegistry,
    ) {
    }

    public function saveAnswer(Uuid $attemptId, SaveExamAnswerRequestDTO $request): ExamAnswerResponseDTO
    {
        $user = $this->security->getUser();

        if (!$user instanceof \App\Entity\User) {
            throw new AuthenticationException('User not authenticated.');
        }

        $attempt = $this->examAttemptRepository->find($attemptId);

        if (!$attempt instanceof ExamAttempt) {
            throw new AuthenticationException('Exam attempt not found.');
        }

        if (!$attempt->getStudent()->getId()->equals($user->getId())) {
            throw new AuthenticationException('You are not authorized to answer this attempt.');
        }

        if (!$attempt->isInProgress()) {
            throw new \App\Common\Exception\BusinessException('Answers can only be saved for in-progress attempts.');
        }

        $question = $this->questionRepository->find(Uuid::fromString($request->questionId));

        if (!$question instanceof Question) {
            throw new \InvalidArgumentException('Question not found.');
        }

        $examSession = $attempt->getExamSession();
        $questionPaper = $question->getQuestionPaper();

        if (!$questionPaper->getId()->equals($examSession->getQuestionPaper()->getId())) {
            throw new \InvalidArgumentException('Question does not belong to this exam session.');
        }

        $this->entityManager->beginTransaction();

        try {
            $lockedAttempt = $this->entityManager->find(
                ExamAttempt::class,
                $attemptId,
                LockMode::PESSIMISTIC_WRITE
            );

            $answer = $this->examAnswerRepository->findByAttemptAndQuestion($attemptId, Uuid::fromString($request->questionId));

            if ($answer === null) {
                $answer = new ExamAnswer();
                $answer->setExamAttempt($lockedAttempt);
                $answer->setQuestion($question);
            }

            if ($question->isMCQ()) {
                $answer->setAnswerText(null);

                if ($request->selectedOptionIds !== null && count($request->selectedOptionIds) > 0) {
                    $answer->clearSelectedOptions();

                    foreach ($request->selectedOptionIds as $optionId) {
                        $option = $this->questionOptionRepository->find(Uuid::fromString($optionId));

                        if (!$option instanceof QuestionOption) {
                            throw new \InvalidArgumentException('Invalid option selected.');
                        }

                        if (!$option->getQuestion()->getId()->equals($question->getId())) {
                            throw new \InvalidArgumentException('Option does not belong to this question.');
                        }

                        $answerOption = new ExamAnswerOption();
                        $answerOption->setExamAnswer($answer);
                        $answerOption->setQuestionOption($option);
                        $answer->addSelectedOption($answerOption);
                    }

                    $answer->setSelectedOption(null);
                } elseif ($request->selectedOptionId !== null) {
                    $selectedOption = $this->questionOptionRepository->find(Uuid::fromString($request->selectedOptionId));

                    if (!$selectedOption instanceof QuestionOption) {
                        throw new \InvalidArgumentException('Invalid option selected.');
                    }

                    if (!$selectedOption->getQuestion()->getId()->equals($question->getId())) {
                        throw new \InvalidArgumentException('Option does not belong to this question.');
                    }

                    $answer->clearSelectedOptions();
                    $answer->setSelectedOption($selectedOption);
                } else {
                    throw new \InvalidArgumentException('No answer provided.');
                }
            } elseif ($question->isDescriptive()) {
                $answer->clearSelectedOptions();
                $answer->setSelectedOption(null);

                if (empty(trim((string) $request->answerText))) {
                    throw new \InvalidArgumentException('Answer text cannot be empty.');
                }

                $answer->setAnswerText($request->answerText);
            } else {
                throw new \InvalidArgumentException('Unsupported question type.');
            }

            $this->entityManager->persist($answer);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($answer);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to save exam answer.', [
                'attempt_id' => (string) $attemptId,
                'question_id' => (string) $request->questionId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function getAnswers(Uuid $attemptId): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof \App\Entity\User) {
            throw new AuthenticationException('User not authenticated.');
        }

        $attempt = $this->examAttemptRepository->find($attemptId);

        if (!$attempt instanceof ExamAttempt) {
            throw new AuthenticationException('Exam attempt not found.');
        }

        if (!$attempt->getStudent()->getId()->equals($user->getId())) {
            throw new AuthenticationException('You are not authorized to view these answers.');
        }

        $answers = $this->examAnswerRepository->findByAttempt($attempt);

        $result = [];
        foreach ($answers as $answer) {
            $answerDto = $this->mapToResponse($answer);
            $questionDto = $this->mapQuestionToStudentResponse($answer->getQuestion());

            $result[] = [
                'answer' => $answerDto,
                'question' => $questionDto,
            ];
        }

        return $result;
    }

    private function mapQuestionToStudentResponse(Question $question): \App\DTO\QuestionPaperModule\StudentQuestionResponseDTO
    {
        $options = [];
        foreach ($question->getOptions() as $option) {
            $options[] = new \App\DTO\QuestionPaperModule\StudentQuestionOptionResponseDTO(
                id: $option->getId()->toRfc4122(),
                text: $option->getOptionText(),
                displayOrder: $option->getDisplayOrder(),
            );
        }

        return new \App\DTO\QuestionPaperModule\StudentQuestionResponseDTO(
            id: $question->getId()->toRfc4122(),
            type: $question->getType()->value,
            questionText: $question->getQuestionText(),
            marks: $question->getMarks(),
            displayOrder: $question->getDisplayOrder(),
            image: $question->getImage(),
            options: $options,
        );
    }

    private function mapToResponse(ExamAnswer $answer): ExamAnswerResponseDTO
    {
        $selectedOptionIds = [];

        foreach ($answer->getSelectedOptions() as $selectedOption) {
            $selectedOptionIds[] = $selectedOption->getQuestionOption()->getId()->toRfc4122();
        }

        return new ExamAnswerResponseDTO(
            id: $answer->getId()->toRfc4122(),
            examAttemptId: $answer->getExamAttempt()->getId()->toRfc4122(),
            questionId: $answer->getQuestion()->getId()->toRfc4122(),
            questionType: $answer->getQuestion()->getType()->value,
            selectedOptionId: $answer->getSelectedOption()?->getId()->toRfc4122(),
            selectedOptionIds: $selectedOptionIds,
            answerText: $answer->getAnswerText(),
            updatedAt: $answer->getUpdatedAt()->format(DateTimeInterface::ATOM),
        );
    }
}
