<?php
declare(strict_types=1);

namespace App\Service\ExamAnswerModule;

use App\DTO\ExamAnswerModule\ExamAnswerResponseDTO;
use App\DTO\ExamAnswerModule\OrganizerExamAnswerResponseDTO;
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
use App\Service\ExamAttemptQuestionProgressModule\ExamAttemptQuestionProgressService;
use App\Service\QuestionPaperModule\QuestionImageService;
use App\Service\ExamAttemptModule\EvaluationService;
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
        private ExamAttemptQuestionProgressService $progressService,
        private QuestionImageService $questionImageService,
        private EvaluationService $evaluationService,
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

        if ($attempt->isOffline()) {
            throw new \App\Common\Exception\BusinessException('This exam attempt is offline and does not accept online answers.');
        }

        if (!$attempt->isInProgress()) {
            throw new \App\Common\Exception\BusinessException('Answers can only be saved for in-progress attempts.');
        }

        if ($attempt->getExpiresAt() !== null && new \DateTimeImmutable() >= $attempt->getExpiresAt()) {
            throw new \App\Common\Exception\BusinessException('This exam attempt has expired. Please submit your answers.');
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

            $isNewAnswer = $answer->getId() === null;
            $answerChanged = $this->hasAnswerChanged($answer, $request);

            $this->entityManager->persist($answer);
            $this->entityManager->flush();
            $this->entityManager->commit();

            if ($isNewAnswer || $answerChanged) {
                $this->progressService->updateProgressForAnswer($attemptId, $question, $isNewAnswer, $answerChanged);
            }

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

    public function getAnswers(Uuid $attemptId, ?Uuid $instituteId = null): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof \App\Entity\User) {
            throw new AuthenticationException('User not authenticated.');
        }

        $attempt = $this->examAttemptRepository->findById($attemptId, $instituteId);

        if (!$attempt instanceof ExamAttempt) {
            throw new AuthenticationException('Exam attempt not found.');
        }

        $roles = $user->getRoles();
        $isStudent = in_array('ROLE_STUDENT', $roles, true);
        $isOrganizer = in_array('ROLE_INSTITUTE', $roles, true)
            || in_array('ROLE_TEACHER', $roles, true)
            || in_array('ROLE_SUPER_ADMIN', $roles, true);

        if ($isStudent && !$attempt->getStudent()->getId()->equals($user->getId())) {
            throw new AuthenticationException('You are not authorized to view these answers.');
        }

        if (!$isStudent && !$isOrganizer) {
            throw new AuthenticationException('You are not authorized to view these answers.');
        }

        if ($attempt->isOffline()) {
            return $isOrganizer ? [] : [];
        }

        $answers = $this->examAnswerRepository->findByAttempt($attempt);

        if ($isOrganizer) {
            return $this->mapOrganizerResponse($answers);
        }

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

    private function mapOrganizerResponse(array $answers): array
    {
        $result = [];

        foreach ($answers as $answer) {
            $question = $answer->getQuestion();
            $correctOption = $this->findCorrectOption($question);
            $isCorrect = null;
            $marksObtained = null;
            $requiresManualEvaluation = false;

            if ($question->isMCQ()) {
                if ($correctOption === null) {
                    $requiresManualEvaluation = true;
                } else {
                    $selectedOption = $answer->getSelectedOption();
                    if ($selectedOption !== null && $selectedOption->getId()->equals($correctOption->getId())) {
                        $isCorrect = true;
                        $marksObtained = $question->getMarks();
                    } else {
                        $isCorrect = false;
                        $marksObtained = 0;
                    }
                }
            } elseif ($question->isDescriptive()) {
                if ($answer->isRequiresManualEvaluation()) {
                    $requiresManualEvaluation = true;
                } else {
                    $isCorrect = $answer->getIsCorrect();
                    $marksObtained = $answer->getMarksObtained();
                }
            }

            $result[] = OrganizerExamAnswerResponseDTO::fromEvaluation(
                question: $question,
                answer: $answer,
                correctOption: $correctOption,
                isCorrect: $isCorrect,
                marksObtained: $marksObtained,
                requiresManualEvaluation: $requiresManualEvaluation,
            );
        }

        return $result;
    }

    private function findCorrectOption(Question $question): ?QuestionOption
    {
        foreach ($question->getOptions() as $option) {
            if ($option->isCorrect()) {
                return $option;
            }
        }

        return null;
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

        $imageUrl = $question->getImageFileReference() !== null
            ? $this->questionImageService->getPublicUrl($question->getImageFileReference())
            : $question->getImage();

        return new \App\DTO\QuestionPaperModule\StudentQuestionResponseDTO(
            id: $question->getId()->toRfc4122(),
            type: $question->getType()->value,
            questionText: $question->getQuestionText(),
            marks: $question->getMarks(),
            displayOrder: $question->getDisplayOrder(),
            image: $question->getImage(),
            imageUrl: $imageUrl,
            imageSource: $question->getImageSource(),
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

    private function hasAnswerChanged(ExamAnswer $answer, SaveExamAnswerRequestDTO $request): bool
    {
        $question = $answer->getQuestion();

        if ($question->isMCQ()) {
            $oldSelectedOptionIds = [];
            foreach ($answer->getSelectedOptions() as $selectedOption) {
                $oldSelectedOptionIds[] = $selectedOption->getQuestionOption()->getId()->toRfc4122();
            }

            $newSelectedOptionIds = $request->selectedOptionIds ?? [];

            if ($request->selectedOptionId !== null) {
                $newSelectedOptionIds = [$request->selectedOptionId];
            }

            sort($oldSelectedOptionIds);
            sort($newSelectedOptionIds);

            return $oldSelectedOptionIds !== $newSelectedOptionIds;
        }

        if ($question->isDescriptive()) {
            $oldText = $answer->getAnswerText() ?? '';
            $newText = trim((string) $request->answerText);

            return $oldText !== $newText;
        }

        return true;
    }

    public function evaluateDescriptiveAnswer(Uuid $attemptId, Uuid $answerId, ?int $marksObtained, ?string $answerText): OrganizerExamAnswerResponseDTO
    {
        $user = $this->security->getUser();

        if (!$user instanceof \App\Entity\User) {
            throw new AuthenticationException('User not authenticated.');
        }

        $isOrganizer = in_array('ROLE_INSTITUTE', $user->getRoles(), true)
            || in_array('ROLE_TEACHER', $user->getRoles(), true)
            || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);

        if (!$isOrganizer) {
            throw new AuthenticationException('You are not authorized to evaluate answers.');
        }

        $attempt = $this->examAttemptRepository->find($attemptId);

        if (!$attempt instanceof ExamAttempt) {
            throw new AuthenticationException('Exam attempt not found.');
        }

        $instituteId = null;
        if (!in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            $institute = $user->getInstitute();
            if (!$institute instanceof \App\Entity\Institute) {
                throw new AuthenticationException('Institute not found.');
            }
            $instituteId = $institute->getId();

            if (!$attempt->getExamSession()->getInstitute()->getId()->equals($instituteId)) {
                throw new AuthenticationException('You are not authorized to evaluate this attempt.');
            }
        }

        $answer = $this->examAnswerRepository->find($answerId);

        if (!$answer instanceof ExamAnswer) {
            throw new AuthenticationException('Exam answer not found.');
        }

        if (!$answer->getExamAttempt()->getId()->equals($attemptId)) {
            throw new AuthenticationException('Answer does not belong to this attempt.');
        }

        $question = $answer->getQuestion();

        if (!$question->isDescriptive()) {
            throw new \InvalidArgumentException('Only descriptive questions can be manually evaluated.');
        }

        if ($marksObtained === null) {
            throw new \InvalidArgumentException('Marks obtained is required for manual evaluation.');
        }

        if ($marksObtained < 0) {
            throw new \InvalidArgumentException('Marks obtained must be greater than or equal to 0.');
        }

        if ($marksObtained > $question->getMarks()) {
            throw new \InvalidArgumentException('Marks obtained cannot exceed the question maximum marks of ' . $question->getMarks() . '.');
        }

        $this->entityManager->beginTransaction();

        try {
            $answer->setMarksObtained($marksObtained);
            $answer->setRequiresManualEvaluation(false);
            $answer->setIsCorrect($marksObtained > 0);

            if ($answerText !== null && trim($answerText) !== '') {
                $answer->setAnswerText($answerText);
            }

            $this->entityManager->persist($answer);
            $this->entityManager->flush();

            $this->evaluationService->evaluateAttempt($attemptId);

            $this->entityManager->commit();

            $correctOption = $this->findCorrectOption($question);

            return OrganizerExamAnswerResponseDTO::fromEvaluation(
                question: $question,
                answer: $answer,
                correctOption: $correctOption,
                isCorrect: $answer->getIsCorrect(),
                marksObtained: $answer->getMarksObtained(),
                requiresManualEvaluation: $answer->isRequiresManualEvaluation(),
            );
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to evaluate descriptive answer.', [
                'attempt_id' => (string) $attemptId,
                'answer_id' => (string) $answerId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
