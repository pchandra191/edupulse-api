<?php
declare(strict_types=1);

namespace App\Service\ExamAttemptModule;

use App\DTO\ExamAttemptModule\ExamAttemptResultResponseDTO;
use App\DTO\ExamAttemptModule\QuestionEvaluationResultDTO;
use App\Entity\ExamAnswer;
use App\Entity\ExamAttempt;
use App\Entity\ExamSession;
use App\Entity\Question;
use App\Entity\QuestionOption;
use App\Enum\ExamAttemptStatus;
use App\Enum\QuestionType;
use App\Repository\ExamAnswerRepository;
use App\Repository\ExamAttemptRepository;
use App\Repository\QuestionOptionRepository;
use App\Repository\QuestionRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

readonly class EvaluationService
{
    public function __construct(
        private ExamAttemptRepository $examAttemptRepository,
        private ExamAnswerRepository $examAnswerRepository,
        private QuestionRepository $questionRepository,
        private QuestionOptionRepository $questionOptionRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function evaluateAttempt(Uuid $attemptId): ExamAttempt
    {
        $attempt = $this->examAttemptRepository->find($attemptId);

        if (!$attempt instanceof ExamAttempt) {
            throw new \InvalidArgumentException('Exam attempt not found.');
        }

        $allowedStatuses = [
            ExamAttemptStatus::Submitted,
            ExamAttemptStatus::AutoSubmitted,
            ExamAttemptStatus::Evaluated,
        ];

        if (!in_array($attempt->getStatus(), $allowedStatuses, true)) {
            throw new \InvalidArgumentException('Only submitted attempts can be evaluated.');
        }

        $examSession = $attempt->getExamSession();
        $questionPaper = $examSession->getQuestionPaper();

        $questions = $this->questionRepository->findByPaper($questionPaper->getId());
        $answers = $this->examAnswerRepository->findByAttempt($attempt);

        $answerMap = [];
        foreach ($answers as $answer) {
            $answerMap[$answer->getQuestion()->getId()->toRfc4122()] = $answer;
        }

        $totalMarks = 0;
        $evaluatedMarks = 0;
        $obtainedMarks = 0;
        $correctAnswers = 0;
        $incorrectAnswers = 0;
        $unanswered = 0;
        $pendingAnswers = 0;
        $requiresManualEvaluation = false;

        foreach ($questions as $question) {
            $totalMarks += $question->getMarks();
            $answer = $answerMap[$question->getId()->toRfc4122()] ?? null;

            if ($question->isMCQ()) {
                $correctOption = $this->findCorrectOption($question);
                $isCorrect = null;
                $marksAwarded = null;

                if ($correctOption === null) {
                    $requiresManualEvaluation = true;
                    $pendingAnswers++;
                } elseif ($answer !== null && $answer->getSelectedOption() !== null) {
                    $selectedOption = $answer->getSelectedOption();
                    if ($selectedOption !== null && $selectedOption->getId()->equals($correctOption->getId())) {
                        $isCorrect = true;
                        $marksAwarded = $question->getMarks();
                    } else {
                        $isCorrect = false;
                        $marksAwarded = 0;
                    }
                } else {
                    $isCorrect = false;
                    $marksAwarded = 0;
                }

                if ($marksAwarded !== null) {
                    $obtainedMarks += $marksAwarded;
                }

                if ($isCorrect === true) {
                    $correctAnswers++;
                } elseif ($isCorrect === false) {
                    $incorrectAnswers++;
                } else {
                    $pendingAnswers++;
                }
            } elseif ($question->isDescriptive()) {
                $hasAnswer = $answer !== null && !empty(trim((string) $answer->getAnswerText()));

                if (!$hasAnswer) {
                    $unanswered++;
                } elseif ($answer !== null && !$answer->isRequiresManualEvaluation() && $answer->getMarksObtained() !== null) {
                    $obtainedMarks += $answer->getMarksObtained();
                } else {
                    $requiresManualEvaluation = true;
                    $pendingAnswers++;
                }
            }
        }

        $percentage = $totalMarks > 0 && $obtainedMarks > 0 ? (int) round(($obtainedMarks / $totalMarks) * 100) : null;

        $attempt->setScore($obtainedMarks);
        $attempt->setMaxMarks($totalMarks);
        $attempt->setPercentage($percentage);

        if ($attempt->getTimeTakenSeconds() === null && $attempt->getStartedAt() !== null && $attempt->getSubmittedAt() !== null) {
            $attempt->setTimeTakenSeconds($attempt->getSubmittedAt()->getTimestamp() - $attempt->getStartedAt()->getTimestamp());
        }

        if (!$requiresManualEvaluation) {
            $attempt->setStatus(ExamAttemptStatus::Evaluated);
        }

        $this->entityManager->persist($attempt);
        $this->entityManager->flush();

        return $attempt;
    }

    public function getResult(Uuid $attemptId, Uuid $studentId): ExamAttemptResultResponseDTO
    {
        $attempt = $this->examAttemptRepository->find($attemptId);

        if (!$attempt instanceof ExamAttempt) {
            throw new \InvalidArgumentException('Exam attempt not found.');
        }

        if (!$attempt->getStudent()->getId()->equals($studentId)) {
            throw new \InvalidArgumentException('You are not authorized to view this result.');
        }

        if (!$attempt->isSubmitted() && !$attempt->isAutoSubmitted() && !$attempt->isEvaluated()) {
            throw new \InvalidArgumentException('Exam attempt has not been submitted yet.');
        }

        $examSession = $attempt->getExamSession();
        $showResultsMode = $examSession->getShowResultsMode();

        if ($showResultsMode === 'IMMEDIATE') {
            // Result available immediately after submission
        } elseif ($showResultsMode === 'AFTER_DEADLINE') {
            $now = new \DateTimeImmutable();
            $availableTo = $examSession->getAvailableTo();
            if ($now < $availableTo) {
                throw new \InvalidArgumentException('Result will be available after the exam deadline.');
            }
        } elseif ($showResultsMode === 'MANUAL') {
            throw new \InvalidArgumentException('Result is not yet available.');
        }

        $questionPaper = $examSession->getQuestionPaper();
        $questions = $this->questionRepository->findByPaper($questionPaper->getId());
        $answers = $this->examAnswerRepository->findByAttempt($attempt);

        $answerMap = [];
        foreach ($answers as $answer) {
            $answerMap[$answer->getQuestion()->getId()->toRfc4122()] = $answer;
        }

        $questionResults = [];
        $totalMarks = 0;
        $obtainedMarks = 0;
        $hasPendingMarks = false;
        $correctAnswers = 0;
        $incorrectAnswers = 0;
        $unanswered = 0;
        $pendingAnswers = 0;
        $requiresManualEvaluation = false;

        foreach ($questions as $question) {
            $totalMarks += $question->getMarks();
            $answer = $answerMap[$question->getId()->toRfc4122()] ?? null;

            if ($question->isMCQ()) {
                $correctOption = $this->findCorrectOption($question);
                $isCorrect = null;
                $marksAwarded = null;

                if ($correctOption === null) {
                    $requiresManualEvaluation = true;
                    $hasPendingMarks = true;
                } elseif ($answer !== null && $answer->getSelectedOption() !== null) {
                    $selectedOption = $answer->getSelectedOption();
                    if ($selectedOption !== null && $selectedOption->getId()->equals($correctOption->getId())) {
                        $isCorrect = true;
                        $marksAwarded = $question->getMarks();
                    } else {
                        $isCorrect = false;
                        $marksAwarded = 0;
                    }
                } else {
                    $isCorrect = false;
                    $marksAwarded = 0;
                }

                if ($marksAwarded !== null) {
                    $obtainedMarks += $marksAwarded;
                } else {
                    $pendingAnswers++;
                }

                if ($isCorrect === true) {
                    $correctAnswers++;
                } elseif ($isCorrect === false) {
                    $incorrectAnswers++;
                } elseif ($correctOption === null) {
                    $pendingAnswers++;
                }

                $questionResults[] = QuestionEvaluationResultDTO::fromEvaluation(
                    question: $question,
                    answer: $answer,
                    correctOption: $correctOption,
                    obtainedMarks: $marksAwarded,
                    isCorrect: $isCorrect,
                    requiresManualEvaluation: $correctOption === null,
                );
            } elseif ($question->isDescriptive()) {
                $hasAnswer = $answer !== null && !empty(trim((string) $answer->getAnswerText()));

                if (!$hasAnswer) {
                    $unanswered++;
                } elseif ($answer !== null && !$answer->isRequiresManualEvaluation() && $answer->getMarksObtained() !== null) {
                    $obtainedMarks += $answer->getMarksObtained();
                    $hasPendingMarks = false;
                } else {
                    $requiresManualEvaluation = true;
                    $hasPendingMarks = true;
                    $pendingAnswers++;
                }

                $obtainedMarksForQuestion = null;
                $requiresManualEvaluationForQuestion = true;

                if ($answer !== null && !$answer->isRequiresManualEvaluation() && $answer->getMarksObtained() !== null) {
                    $obtainedMarksForQuestion = $answer->getMarksObtained();
                    $requiresManualEvaluationForQuestion = false;
                }

                $questionResults[] = QuestionEvaluationResultDTO::fromEvaluation(
                    question: $question,
                    answer: $answer,
                    correctOption: null,
                    obtainedMarks: $obtainedMarksForQuestion,
                    isCorrect: null,
                    requiresManualEvaluation: $requiresManualEvaluationForQuestion,
                );
            }
        }

        $percentage = $totalMarks > 0 ? (int) round(($obtainedMarks / $totalMarks) * 100) : 0;

        return new ExamAttemptResultResponseDTO(
            attemptId: $attempt->getId()->toRfc4122(),
            examSessionId: $examSession->getId()->toRfc4122(),
            examSessionTitle: $examSession->getTitle(),
            status: $attempt->getStatus()->value,
            totalMarks: $totalMarks,
            maxMarks: $totalMarks,
            obtainedMarks: $obtainedMarks,
            percentage: $percentage,
            correctAnswers: $correctAnswers,
            incorrectAnswers: $incorrectAnswers,
            unanswered: $unanswered,
            pendingAnswers: $pendingAnswers,
            requiresManualEvaluation: $requiresManualEvaluation,
            questionResults: $questionResults,
            submittedAt: $attempt->getSubmittedAt()?->format(DateTimeInterface::ATOM),
        );
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
}
