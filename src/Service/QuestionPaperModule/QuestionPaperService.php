<?php
declare(strict_types=1);

namespace App\Service\QuestionPaperModule;

use App\DTO\QuestionPaperModule\CreateQuestionPaperRequestDTO;
use App\DTO\QuestionPaperModule\CreateQuestionRequestDTO;
use App\DTO\QuestionPaperModule\GenerateAiQuestionRequestDTO;
use App\DTO\QuestionPaperModule\GenerateAiQuestionResponseDTO;
use App\DTO\QuestionPaperModule\QuestionPaperFilterRequest;
use App\DTO\QuestionPaperModule\QuestionPaperPreviewResponseDTO;
use App\DTO\QuestionPaperModule\QuestionPaperResponseDTO;
use App\DTO\QuestionPaperModule\QuestionResponseDTO;
use App\DTO\QuestionPaperModule\UpdateQuestionPaperRequestDTO;
use App\DTO\QuestionPaperModule\UpdateQuestionRequestDTO;
use App\DTO\Response\QuestionPaperListResponse;
use App\Entity\AcademicClass;
use App\Entity\AcademicYear;
use App\Entity\Examination;
use App\Entity\Institute;
use App\Entity\Question;
use App\Entity\QuestionPaper;
use App\Entity\Section;
use App\Entity\Subject;
use App\Enum\QuestionPaperStatus;
use App\Enum\QuestionType;
use App\Exception\AuthenticationException;
use App\Repository\QuestionPaperRepository;
use App\Repository\QuestionRepository;
use App\Repository\QuestionOptionRepository;
use App\Repository\InstituteRepository;
use DateTimeInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Uid\Uuid;

readonly class QuestionPaperService
{
    public function __construct(
        private Security $security,
        private QuestionPaperRepository $questionPaperRepository,
        private QuestionRepository $questionRepository,
        private QuestionOptionRepository $questionOptionRepository,
        private InstituteRepository $instituteRepository,
        private AiQuestionGenerationServiceInterface $aiQuestionGenerationService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private \Symfony\Component\Workflow\Registry $workflowRegistry,
    ) {
    }

    public function getQuestionPapers(?Uuid $instituteId, QuestionPaperFilterRequest $filter): QuestionPaperListResponse
    {
        $result = $this->questionPaperRepository->findPaginated(
            $instituteId,
            $filter->search,
            $filter->academicYearId ? Uuid::fromString($filter->academicYearId) : null,
            $filter->subjectId ? Uuid::fromString($filter->subjectId) : null,
            $filter->examinationId ? Uuid::fromString($filter->examinationId) : null,
            $filter->status,
            $filter->page,
            $filter->limit,
            $filter->sortBy,
            $filter->sortOrder,
        );

        $paperIds = [];
        foreach ($result['items'] as $paper) {
            $paperIds[] = $paper->getId()->toRfc4122();
        }

        $classesByPaper = $this->questionPaperRepository->findClassesByPaperIds($paperIds);
        $sectionsByPaper = $this->questionPaperRepository->findSectionsByPaperIds($paperIds);
        $questionCounts = $this->questionPaperRepository->findQuestionCountsByPaperIds($paperIds);

        $items = array_map(
            fn (QuestionPaper $paper) => $this->mapToListItemResponse($paper, $classesByPaper, $sectionsByPaper, $questionCounts),
            $result['items'],
        );

        return new QuestionPaperListResponse(
            items: $items,
            pagination: $result['pagination'],
        );
    }

    public function getQuestionPaper(Uuid $id, ?Uuid $instituteId): QuestionPaperResponseDTO
    {
        $paper = $this->questionPaperRepository->findById($id, $instituteId);

        if (!$paper instanceof QuestionPaper) {
            throw new AuthenticationException('Question paper not found.');
        }

        return $this->mapToResponse($paper);
    }

    public function createQuestionPaper(Uuid $instituteId, CreateQuestionPaperRequestDTO $request): QuestionPaperResponseDTO
    {
        $institute = $this->instituteRepository->find($instituteId);

        if (!$institute instanceof Institute) {
            throw new AuthenticationException('Institute not found.');
        }

        $academicYear = $this->entityManager->getRepository(AcademicYear::class)->find($request->academicYearId);

        if (!$academicYear instanceof AcademicYear) {
            throw new \InvalidArgumentException('Academic year not found.');
        }

        if (!$academicYear->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Academic year does not belong to the authenticated institute.');
        }

        $subject = $this->entityManager->getRepository(Subject::class)->find($request->subjectId);

        if (!$subject instanceof Subject) {
            throw new \InvalidArgumentException('Subject not found.');
        }

        if (!$subject->getInstitute()->getId()->equals($instituteId)) {
            throw new \InvalidArgumentException('Subject does not belong to the authenticated institute.');
        }

        $classes = [];
        foreach ($request->classIds as $classId) {
            $class = $this->entityManager->getRepository(AcademicClass::class)->find($classId);

            if (!$class instanceof AcademicClass) {
                throw new \InvalidArgumentException('Class not found: ' . $classId);
            }

            if (!$class->getAcademicYear()->getId()->equals($academicYear->getId())) {
                throw new \InvalidArgumentException('Class does not belong to the selected academic year.');
            }

            if (!$class->getInstitute()->getId()->equals($instituteId)) {
                throw new \InvalidArgumentException('Class does not belong to the authenticated institute.');
            }

            $classes[] = $class;
        }

        $sections = [];
        foreach ($request->sectionIds as $sectionId) {
            $section = $this->entityManager->getRepository(Section::class)->find($sectionId);

            if (!$section instanceof Section) {
                throw new \InvalidArgumentException('Section not found: ' . $sectionId);
            }

            $classBelongsTo = false;
            foreach ($classes as $class) {
                if ($section->getAcademicClass()->getId()->equals($class->getId())) {
                    $classBelongsTo = true;
                    break;
                }
            }

            if (!$classBelongsTo) {
                throw new \InvalidArgumentException('Section does not belong to any selected class.');
            }

            if (!$section->getAcademicClass()->getInstitute()->getId()->equals($instituteId)) {
                throw new \InvalidArgumentException('Section does not belong to the authenticated institute.');
            }

            $sections[] = $section;
        }

        $examination = null;
        if ($request->examinationId !== null && $request->examinationId !== '') {
            $examination = $this->entityManager->getRepository(Examination::class)->find($request->examinationId);

            if (!$examination instanceof Examination) {
                throw new \InvalidArgumentException('Examination not found.');
            }

            if (!$examination->getInstitute()->getId()->equals($instituteId)) {
                throw new \InvalidArgumentException('Examination does not belong to the authenticated institute.');
            }
        }

        if ($this->questionPaperRepository->titleExistsForInstitute($request->title, $instituteId)) {
            throw new \App\Common\Exception\BusinessException('Question paper title already exists for this institute.');
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $request->date);

        if (!$date) {
            throw new \InvalidArgumentException('Invalid date format. Expected YYYY-MM-DD.');
        }

        try {
            $this->entityManager->beginTransaction();

            $paper = new QuestionPaper();
            $paper->setInstitute($institute);
            $paper->setAcademicYear($academicYear);
            $paper->setSubject($subject);
            $paper->setTitle($request->title);
            $paper->setDate($date);
            $paper->setDuration($request->duration);
            $paper->setMaximumMarks($request->maximumMarks);
            $paper->setInstructions($request->instructions);
            $paper->setStatus(QuestionPaperStatus::Draft);
            $paper->setCreatedBy($request->createdBy);

            foreach ($classes as $class) {
                $paper->addClass($class);
            }

            foreach ($sections as $section) {
                $paper->addSection($section);
            }

            if ($examination !== null) {
                $paper->setExamination($examination);
            }

            $this->entityManager->persist($paper);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($paper);
        } catch (UniqueConstraintViolationException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw new \App\Common\Exception\BusinessException('Question paper title already exists for this institute.', previous: $e);
        } catch (\ValueError $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw new \InvalidArgumentException('Invalid status value.', previous: $e);
        } catch (\InvalidArgumentException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw $e;
        } catch (\TypeError $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw new \InvalidArgumentException('Invalid request data type: ' . $e->getMessage(), previous: $e);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to create question paper.', [
                'institute_id' => (string) $instituteId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to create question paper. Please try again.', previous: $e);
        }
    }

    public function updateQuestionPaper(Uuid $id, ?Uuid $instituteId, UpdateQuestionPaperRequestDTO $request): QuestionPaperResponseDTO
    {
        $paper = $this->questionPaperRepository->findById($id, $instituteId);

        if (!$paper instanceof QuestionPaper) {
            throw new AuthenticationException('Question paper not found.');
        }

        if ($paper->isFinalized() || $paper->isReview() || $paper->isArchived()) {
            throw new \App\Common\Exception\BusinessException('Finalized, review, or archived question papers cannot be modified.');
        }

        try {
            $this->entityManager->beginTransaction();
            $this->updateFields($paper, $request);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapToResponse($paper);
        } catch (\App\Common\Exception\BusinessException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw $e;
        } catch (\ValueError $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw new \InvalidArgumentException('Invalid status value.', previous: $e);
        } catch (\InvalidArgumentException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw $e;
        } catch (\TypeError $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw new \InvalidArgumentException('Invalid request data type: ' . $e->getMessage(), previous: $e);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to update question paper.', [
                'question_paper_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to update question paper. Please try again.', previous: $e);
        }
    }

    public function deleteQuestionPaper(Uuid $id, ?Uuid $instituteId): void
    {
        $paper = $this->questionPaperRepository->findById($id, $instituteId);

        if (!$paper instanceof QuestionPaper) {
            throw new AuthenticationException('Question paper not found.');
        }

        if ($paper->isArchived()) {
            throw new \App\Common\Exception\BusinessException('Archived question papers cannot be deleted.');
        }

        try {
            $workflow = $this->workflowRegistry->get($paper);

            if (!$workflow->can($paper, 'archive')) {
                throw new \App\Common\Exception\BusinessException('Question paper cannot be archived from its current state.');
            }

            $workflow->apply($paper, 'archive');

            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete question paper.', [
                'question_paper_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to delete question paper. Please try again.', previous: $e);
        }
    }

    public function submitForReview(Uuid $id, ?Uuid $instituteId): QuestionPaperResponseDTO
    {
        $paper = $this->questionPaperRepository->findById($id, $instituteId);

        if (!$paper instanceof QuestionPaper) {
            throw new AuthenticationException('Question paper not found.');
        }

        if (!$paper->isDraft()) {
            throw new \App\Common\Exception\BusinessException('Only draft question papers can be submitted for review.');
        }

        $questionCount = $this->questionRepository->countByPaper($paper->getId());

        if ($questionCount === 0) {
            throw new \InvalidArgumentException('At least one question is required to submit for review.');
        }

        try {
            $workflow = $this->workflowRegistry->get($paper);

            if (!$workflow->can($paper, 'submit_for_review')) {
                throw new \App\Common\Exception\BusinessException('Question paper cannot be submitted for review from its current state.');
            }

            $workflow->apply($paper, 'submit_for_review');

            $this->entityManager->flush();

            return $this->mapToResponse($paper);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to submit question paper for review.', [
                'question_paper_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to submit question paper for review. Please try again.', previous: $e);
        }
    }

    public function requestChanges(Uuid $id, ?Uuid $instituteId): QuestionPaperResponseDTO
    {
        $paper = $this->questionPaperRepository->findById($id, $instituteId);

        if (!$paper instanceof QuestionPaper) {
            throw new AuthenticationException('Question paper not found.');
        }

        if (!$paper->isReview()) {
            throw new \App\Common\Exception\BusinessException('Only question papers under review can be sent back for changes.');
        }

        try {
            $workflow = $this->workflowRegistry->get($paper);

            if (!$workflow->can($paper, 'request_changes')) {
                throw new \App\Common\Exception\BusinessException('Question paper cannot be sent back for changes from its current state.');
            }

            $workflow->apply($paper, 'request_changes');

            $this->entityManager->flush();

            return $this->mapToResponse($paper);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to request changes on question paper.', [
                'question_paper_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to request changes on question paper. Please try again.', previous: $e);
        }
    }

    public function finalizeQuestionPaper(Uuid $id, ?Uuid $instituteId): QuestionPaperResponseDTO
    {
        $paper = $this->questionPaperRepository->findById($id, $instituteId);

        if (!$paper instanceof QuestionPaper) {
            throw new AuthenticationException('Question paper not found.');
        }

        if (!$paper->isReview()) {
            throw new \App\Common\Exception\BusinessException('Only question papers under review can be finalized.');
        }

        $this->validateQuestionPaperContent($paper);

        try {
            $workflow = $this->workflowRegistry->get($paper);

            if (!$workflow->can($paper, 'finalize')) {
                throw new \App\Common\Exception\BusinessException('Only question papers under review can be finalized.');
            }

            $workflow->apply($paper, 'finalize');

            $this->entityManager->flush();

            return $this->mapToResponse($paper);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to finalize question paper.', [
                'question_paper_id' => (string) $id,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to finalize question paper. Please try again.', previous: $e);
        }
    }

    private function validateQuestionPaperContent(QuestionPaper $paper): void
    {
        if (empty($paper->getTitle())) {
            throw new \InvalidArgumentException('Title is required.');
        }

        $questionCount = $this->questionRepository->countByPaper($paper->getId());

        if ($questionCount === 0) {
            throw new \InvalidArgumentException('At least one question is required.');
        }

        $questions = $this->questionRepository->findByPaper($paper->getId());

        $questionIds = array_map(static fn (Question $q) => $q->getId()->toRfc4122(), $questions);
        $optionsByQuestion = $this->questionOptionRepository->findByQuestionIds($questionIds);

        foreach ($questions as $question) {
            if (empty($question->getQuestionText())) {
                throw new \InvalidArgumentException('All questions must have text.');
            }

            if ($question->getMarks() <= 0) {
                throw new \InvalidArgumentException('All questions must have positive marks.');
            }

            $questionId = $question->getId()->toRfc4122();
            $rawOptions = $optionsByQuestion[$questionId] ?? [];

            if ($question->isMCQ()) {
                if (empty($rawOptions)) {
                    throw new \InvalidArgumentException('MCQ questions must have at least one option.');
                }

                $hasValidOption = false;
                foreach ($rawOptions as $optionRow) {
                    if (empty($optionRow['optionText'])) {
                        throw new \InvalidArgumentException('All MCQ options must have text.');
                    }
                    $hasValidOption = true;
                }

                if (!$hasValidOption) {
                    throw new \InvalidArgumentException('MCQ questions must have at least one option.');
                }
            }

            if ($question->isDescriptive()) {
                if (!empty($rawOptions)) {
                    throw new \InvalidArgumentException('Descriptive questions must not have options.');
                }
            }
        }
    }

    public function getPreview(Uuid $id, ?Uuid $instituteId): QuestionPaperPreviewResponseDTO
    {
        $paper = $this->questionPaperRepository->findById($id, $instituteId);

        if (!$paper instanceof QuestionPaper) {
            throw new AuthenticationException('Question paper not found.');
        }

        $institute = $paper->getInstitute();
        $academicYear = $paper->getAcademicYear();
        $subject = $paper->getSubject();

        $classNames = [];
        foreach ($paper->getClasses() as $class) {
            $classNames[] = $class->getName();
        }

        $sectionNames = [];
        foreach ($paper->getSections() as $section) {
            $sectionNames[] = $section->getName();
        }

        $questions = $this->questionRepository->findByPaper($paper->getId());

        $questionIds = array_map(static fn (Question $q) => $q->getId()->toRfc4122(), $questions);
        $optionsByQuestion = $this->questionOptionRepository->findByQuestionIds($questionIds);

        $questionDtos = array_map(
            fn (Question $question) => $this->mapQuestionToResponse($question, $optionsByQuestion),
            $questions,
        );

        return new QuestionPaperPreviewResponseDTO(
            instituteName: $institute->getName(),
            title: $paper->getTitle(),
            subjectName: $subject->getName(),
            academicYearName: $academicYear->getName(),
            classNames: $classNames,
            sectionNames: $sectionNames,
            examinationName: $paper->getExamination()?->getName(),
            date: $paper->getDate()->format('Y-m-d'),
            duration: $paper->getDuration(),
            maximumMarks: $paper->getMaximumMarks(),
            instructions: $paper->getInstructions(),
            status: $paper->getStatus()->value,
            questions: $questionDtos,
        );
    }

    public function generatePdf(Uuid $id, ?Uuid $instituteId): \Symfony\Component\HttpFoundation\Response
    {
        $paper = $this->questionPaperRepository->findById($id, $instituteId);

        if (!$paper instanceof QuestionPaper) {
            throw new AuthenticationException('Question paper not found.');
        }

        $preview = $this->getPreview($id, $instituteId);

        $html = $this->renderPdfHtml($preview);

        return new \Symfony\Component\HttpFoundation\Response(
            $html,
            200,
            ['Content-Type' => 'text/html']
        );
    }

    public function getQuestions(Uuid $paperId, ?Uuid $instituteId): array
    {
        $paper = $this->questionPaperRepository->findById($paperId, $instituteId);

        if (!$paper instanceof QuestionPaper) {
            throw new AuthenticationException('Question paper not found.');
        }

        $questions = $this->questionRepository->findByPaper($paperId);

        return array_map(
            fn (Question $question) => $this->mapQuestionToResponse($question),
            $questions,
        );
    }

    public function getQuestion(Uuid $paperId, Uuid $questionId, ?Uuid $instituteId): QuestionResponseDTO
    {
        $paper = $this->questionPaperRepository->findById($paperId, $instituteId);

        if (!$paper instanceof QuestionPaper) {
            throw new AuthenticationException('Question paper not found.');
        }

        $question = $this->questionRepository->findById($questionId, $paperId);

        if (!$question instanceof Question) {
            throw new AuthenticationException('Question not found.');
        }

        return $this->mapQuestionToResponse($question);
    }

    public function createQuestion(Uuid $paperId, ?Uuid $instituteId, CreateQuestionRequestDTO $request): QuestionResponseDTO
    {
        $paper = $this->questionPaperRepository->findById($paperId, $instituteId);

        if (!$paper instanceof QuestionPaper) {
            throw new AuthenticationException('Question paper not found.');
        }

        if (!$paper->isDraft()) {
            throw new \App\Common\Exception\BusinessException('Questions can only be added to draft question papers.');
        }

        $type = QuestionType::from($request->type);

        if ($type === QuestionType::MCQ && empty($request->options)) {
            throw new \InvalidArgumentException('MCQ questions must have at least one option.');
        }

        if ($type === QuestionType::Descriptive && !empty($request->options)) {
            throw new \InvalidArgumentException('Descriptive questions must not have options.');
        }

        try {
            $this->entityManager->beginTransaction();

            $maxOrder = $this->questionRepository->findMaxOrder($paperId);

            $question = new Question();
            $question->setQuestionPaper($paper);
            $question->setType($type);
            $question->setQuestionText($request->questionText);
            $question->setMarks($request->marks);
            $question->setDisplayOrder($request->displayOrder > 0 ? $request->displayOrder : $maxOrder + 1);
            $question->setImage($request->image);

            $this->entityManager->persist($question);
            $this->entityManager->flush();

            if ($type === QuestionType::MCQ && !empty($request->options)) {
                foreach ($request->options as $index => $optionData) {
                    [$optionText, $isCorrect] = $this->normalizeOption($optionData);

                    $option = new \App\Entity\QuestionOption();
                    $option->setOptionText($optionText);
                    $option->setDisplayOrder($index + 1);
                    $option->setIsCorrect($isCorrect);

                    $question->addOption($option);

                    $this->entityManager->persist($option);
                }

                $this->entityManager->flush();
            }

            $this->entityManager->commit();

            return $this->mapQuestionToResponse($question);
        } catch (\ValueError $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw new \InvalidArgumentException('Invalid question type.', previous: $e);
        } catch (\InvalidArgumentException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw $e;
        } catch (\TypeError $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw new \InvalidArgumentException('Invalid request data type: ' . $e->getMessage(), previous: $e);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to create question.', [
                'question_paper_id' => (string) $paperId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to create question. Please try again.', previous: $e);
        }
    }

    public function updateQuestion(Uuid $paperId, Uuid $questionId, ?Uuid $instituteId, UpdateQuestionRequestDTO $request): QuestionResponseDTO
    {
        $paper = $this->questionPaperRepository->findById($paperId, $instituteId);

        if (!$paper instanceof QuestionPaper) {
            throw new AuthenticationException('Question paper not found.');
        }

        if (!$paper->isDraft()) {
            throw new \App\Common\Exception\BusinessException('Questions can only be modified in draft question papers.');
        }

        $question = $this->questionRepository->findById($questionId, $paperId);

        if (!$question instanceof Question) {
            throw new AuthenticationException('Question not found.');
        }

        try {
            $this->entityManager->beginTransaction();
            $this->updateQuestionFields($paper, $question, $request);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->mapQuestionToResponse($question);
        } catch (\ValueError $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw new \InvalidArgumentException('Invalid question type.', previous: $e);
        } catch (\InvalidArgumentException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw $e;
        } catch (\TypeError $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw new \InvalidArgumentException('Invalid request data type: ' . $e->getMessage(), previous: $e);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to update question.', [
                'question_id' => (string) $questionId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to update question. Please try again.', previous: $e);
        }
    }

    public function deleteQuestion(Uuid $paperId, Uuid $questionId, ?Uuid $instituteId): void
    {
        $paper = $this->questionPaperRepository->findById($paperId, $instituteId);

        if (!$paper instanceof QuestionPaper) {
            throw new AuthenticationException('Question paper not found.');
        }

        if (!$paper->isDraft()) {
            throw new \App\Common\Exception\BusinessException('Questions can only be deleted from draft question papers.');
        }

        $question = $this->questionRepository->findById($questionId, $paperId);

        if (!$question instanceof Question) {
            throw new AuthenticationException('Question not found.');
        }

        try {
            $this->questionOptionRepository->deleteByQuestion($questionId);
            $this->entityManager->remove($question);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete question.', [
                'question_id' => (string) $questionId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to delete question. Please try again.', previous: $e);
        }
    }

    public function reorderQuestions(Uuid $paperId, ?Uuid $instituteId, array $orderedIds): array
    {
        $paper = $this->questionPaperRepository->findById($paperId, $instituteId);

        if (!$paper instanceof QuestionPaper) {
            throw new AuthenticationException('Question paper not found.');
        }

        if (!$paper->isDraft()) {
            throw new \App\Common\Exception\BusinessException('Questions can only be reordered in draft question papers.');
        }

        try {
            $this->entityManager->beginTransaction();

            foreach ($orderedIds as $order => $questionId) {
                $question = $this->questionRepository->findById(Uuid::fromString($questionId), $paperId);

                if ($question instanceof Question) {
                    $question->setDisplayOrder($order + 1);
                }
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->getQuestions($paperId, $instituteId);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to reorder questions.', [
                'question_paper_id' => (string) $paperId,
                'error' => $e->getMessage(),
            ]);

            throw new \App\Common\Exception\BusinessException('Failed to reorder questions. Please try again.', previous: $e);
        }
    }

    public function generateAiQuestion(Uuid $paperId, ?Uuid $instituteId, GenerateAiQuestionRequestDTO $request): GenerateAiQuestionResponseDTO
    {
        $paper = $this->questionPaperRepository->findById($paperId, $instituteId);

        if (!$paper instanceof QuestionPaper) {
            throw new AuthenticationException('Question paper not found.');
        }

        $subject = $paper->getSubject()->getName();
        $academicYear = $paper->getAcademicYear()->getName();

        return $this->aiQuestionGenerationService->generateQuestion(
            subject: $subject,
            topic: $request->topic,
            type: $request->type,
            difficulty: $request->difficulty,
            marks: $request->marks,
            instruction: $request->instruction,
        );
    }

    private function updateFields(QuestionPaper $paper, UpdateQuestionPaperRequestDTO $request): void
    {
        if ($request->title !== null && $request->title !== $paper->getTitle()) {
            $instituteId = $paper->getInstitute()->getId();

            if ($this->questionPaperRepository->titleExistsForInstitute($request->title, $instituteId, $paper->getId())) {
                throw new \App\Common\Exception\BusinessException('Question paper title already exists for this institute.');
            }

            $paper->setTitle($request->title);
        }

        if ($request->date !== null && $request->date !== $paper->getDate()->format('Y-m-d')) {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d', $request->date);

            if (!$date) {
                throw new \InvalidArgumentException('Invalid date format. Expected YYYY-MM-DD.');
            }

            $paper->setDate($date);
        }

        if ($request->duration !== null && $request->duration !== $paper->getDuration()) {
            if ($request->duration <= 0) {
                throw new \InvalidArgumentException('Duration must be a positive integer.');
            }

            $paper->setDuration($request->duration);
        }

        if ($request->maximumMarks !== null && $request->maximumMarks !== $paper->getMaximumMarks()) {
            if ($request->maximumMarks < 0) {
                throw new \InvalidArgumentException('Maximum marks cannot be negative.');
            }

            $paper->setMaximumMarks($request->maximumMarks);
        }

        if ($request->instructions !== null && $request->instructions !== $paper->getInstructions()) {
            $paper->setInstructions($request->instructions);
        }

        if ($request->status !== null) {
            $target = QuestionPaperStatus::from($request->status);
            $current = $paper->getStatus();

            if ($current !== $target) {
                $transition = $this->resolveQuestionPaperTransition($current, $target);

                if ($transition === null || !$this->workflowRegistry->get($paper)->can($paper, $transition)) {
                    throw new \App\Common\Exception\BusinessException(
                        sprintf('Invalid question paper status transition from %s to %s.', $current->value, $target->value)
                    );
                }

                $this->workflowRegistry->get($paper)->apply($paper, $transition);
            }
        }

        if ($request->classIds !== null) {
            $paper->getClasses()->clear();

            $academicYear = $paper->getAcademicYear();
            $instituteId = $paper->getInstitute()->getId();

            foreach ($request->classIds as $classId) {
                $class = $this->entityManager->getRepository(AcademicClass::class)->find($classId);

                if (!$class instanceof AcademicClass) {
                    throw new \InvalidArgumentException('Class not found: ' . $classId);
                }

                if (!$class->getAcademicYear()->getId()->equals($academicYear->getId())) {
                    throw new \InvalidArgumentException('Class does not belong to the selected academic year.');
                }

                if (!$class->getInstitute()->getId()->equals($instituteId)) {
                    throw new \InvalidArgumentException('Class does not belong to the authenticated institute.');
                }

                $paper->addClass($class);
            }
        }

        if ($request->sectionIds !== null) {
            $paper->getSections()->clear();

            $validClassIds = [];
            foreach ($paper->getClasses() as $class) {
                $validClassIds[] = $class->getId()->toRfc4122();
            }

            $instituteId = $paper->getInstitute()->getId();

            foreach ($request->sectionIds as $sectionId) {
                $section = $this->entityManager->getRepository(Section::class)->find($sectionId);

                if (!$section instanceof Section) {
                    throw new \InvalidArgumentException('Section not found: ' . $sectionId);
                }

                if (!in_array($section->getAcademicClass()->getId()->toRfc4122(), $validClassIds, true)) {
                    throw new \InvalidArgumentException('Section does not belong to any selected class.');
                }

                if (!$section->getAcademicClass()->getInstitute()->getId()->equals($instituteId)) {
                    throw new \InvalidArgumentException('Section does not belong to the authenticated institute.');
                }

                $paper->addSection($section);
            }
        }

        if ($request->examinationId !== null) {
            $examination = $this->entityManager->getRepository(Examination::class)->find($request->examinationId);

            if (!$examination instanceof Examination) {
                throw new \InvalidArgumentException('Examination not found.');
            }

            if (!$examination->getInstitute()->getId()->equals($paper->getInstitute()->getId())) {
                throw new \InvalidArgumentException('Examination does not belong to the authenticated institute.');
            }

            $paper->setExamination($examination);
        } elseif ($request->examinationId === '') {
            $paper->setExamination(null);
        }
    }

    private function updateQuestionFields(QuestionPaper $paper, Question $question, UpdateQuestionRequestDTO $request): void
    {
        if ($request->type !== null && $request->type !== $question->getType()->value) {
            $question->setType(QuestionType::from($request->type));

            if ($question->isMCQ() && (empty($request->options) || $request->options === [])) {
                throw new \InvalidArgumentException('MCQ questions must have at least one option.');
            }

            if ($question->isDescriptive() && !empty($request->options)) {
                throw new \InvalidArgumentException('Descriptive questions must not have options.');
            }
        }

        if ($request->questionText !== null) {
            $question->setQuestionText($request->questionText);
        }

        if ($request->marks !== null) {
            if ($request->marks <= 0) {
                throw new \InvalidArgumentException('Marks must be positive.');
            }

            $question->setMarks($request->marks);
        }

        if ($request->displayOrder !== null) {
            $question->setDisplayOrder($request->displayOrder);
        }

        if ($request->image !== null) {
            $question->setImage($request->image);
        }

        if ($request->options !== null) {
            if ($question->isDescriptive()) {
                throw new \InvalidArgumentException('Descriptive questions must not have options.');
            }

            $this->questionOptionRepository->deleteByQuestion($question->getId());

            foreach ($request->options as $index => $optionData) {
                [$optionText, $isCorrect] = $this->normalizeOption($optionData);

                $option = new \App\Entity\QuestionOption();
                $option->setQuestion($question);
                $option->setOptionText($optionText);
                $option->setDisplayOrder($index + 1);
                $option->setIsCorrect($isCorrect);

                $this->entityManager->persist($option);
            }
        }
    }

    private function resolveQuestionPaperTransition(QuestionPaperStatus $from, QuestionPaperStatus $to): ?string
    {
        // Finalization is intentionally only reachable through the dedicated
        // /finalize endpoint, which enforces business validation (title,
        // question and option rules). The generic status update therefore only
        // supports the draft -> review -> draft loop and archiving.
        return match (true) {
            $from === $to => null,
            $from === QuestionPaperStatus::Draft && $to === QuestionPaperStatus::Review => 'submit_for_review',
            $from === QuestionPaperStatus::Review && $to === QuestionPaperStatus::Draft => 'request_changes',
            $from === QuestionPaperStatus::Draft && $to === QuestionPaperStatus::Archived => 'archive',
            $from === QuestionPaperStatus::Review && $to === QuestionPaperStatus::Archived => 'archive',
            $from === QuestionPaperStatus::Finalized && $to === QuestionPaperStatus::Archived => 'archive',
            default => null,
        };
    }

    private function normalizeOption(mixed $option): array
    {
        if (is_array($option)) {
            $text = isset($option['text']) ? (string) $option['text'] : '';
            $isCorrect = isset($option['isCorrect']) ? (bool) $option['isCorrect'] : false;

            return [$text, $isCorrect];
        }

        return [(string) $option, false];
    }

    private function mapToListItemResponse(
        QuestionPaper $paper,
        array $classesByPaper,
        array $sectionsByPaper,
        array $questionCounts
    ): QuestionPaperResponseDTO {
        $institute = $paper->getInstitute();
        $academicYear = $paper->getAcademicYear();
        $subject = $paper->getSubject();
        $paperId = $paper->getId()->toRfc4122();

        $classIds = [];
        $classNames = [];
        foreach ($classesByPaper[$paperId] ?? [] as $classRow) {
            $classIds[] = $classRow['id'];
            $classNames[] = $classRow['name'];
        }

        $sectionIds = [];
        $sectionNames = [];
        foreach ($sectionsByPaper[$paperId] ?? [] as $sectionRow) {
            $sectionIds[] = $sectionRow['id'];
            $sectionNames[] = $sectionRow['name'];
        }

        return new QuestionPaperResponseDTO(
            id: $paperId,
            instituteId: $institute->getId()->toRfc4122(),
            instituteName: $institute->getName(),
            academicYearId: $academicYear->getId()->toRfc4122(),
            academicYearName: $academicYear->getName(),
            subjectId: $subject->getId()->toRfc4122(),
            subjectName: $subject->getName(),
            classIds: $classIds,
            classNames: $classNames,
            sectionIds: $sectionIds,
            sectionNames: $sectionNames,
            examinationId: $paper->getExamination()?->getId()->toRfc4122(),
            examinationName: $paper->getExamination()?->getName(),
            title: $paper->getTitle(),
            date: $paper->getDate()->format('Y-m-d'),
            duration: $paper->getDuration(),
            maximumMarks: $paper->getMaximumMarks(),
            instructions: $paper->getInstructions(),
            status: $paper->getStatus()->value,
            createdBy: $paper->getCreatedBy(),
            questionsCount: $questionCounts[$paperId] ?? 0,
            createdAt: $paper->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $paper->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }

    private function mapToResponse(QuestionPaper $paper): QuestionPaperResponseDTO
    {
        $institute = $paper->getInstitute();
        $academicYear = $paper->getAcademicYear();
        $subject = $paper->getSubject();

        $classIds = [];
        $classNames = [];
        foreach ($paper->getClasses() as $class) {
            $classIds[] = $class->getId()->toRfc4122();
            $classNames[] = $class->getName();
        }

        $sectionIds = [];
        $sectionNames = [];
        foreach ($paper->getSections() as $section) {
            $sectionIds[] = $section->getId()->toRfc4122();
            $sectionNames[] = $section->getName();
        }

        return new QuestionPaperResponseDTO(
            id: $paper->getId()->toRfc4122(),
            instituteId: $institute->getId()->toRfc4122(),
            instituteName: $institute->getName(),
            academicYearId: $academicYear->getId()->toRfc4122(),
            academicYearName: $academicYear->getName(),
            subjectId: $subject->getId()->toRfc4122(),
            subjectName: $subject->getName(),
            classIds: $classIds,
            classNames: $classNames,
            sectionIds: $sectionIds,
            sectionNames: $sectionNames,
            examinationId: $paper->getExamination()?->getId()->toRfc4122(),
            examinationName: $paper->getExamination()?->getName(),
            title: $paper->getTitle(),
            date: $paper->getDate()->format('Y-m-d'),
            duration: $paper->getDuration(),
            maximumMarks: $paper->getMaximumMarks(),
            instructions: $paper->getInstructions(),
            status: $paper->getStatus()->value,
            createdBy: $paper->getCreatedBy(),
            questionsCount: $this->questionRepository->countByPaper($paper->getId()),
            createdAt: $paper->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $paper->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }

    private function mapQuestionToResponse(Question $question, array $optionsByQuestion = []): QuestionResponseDTO
    {
        $questionId = $question->getId()->toRfc4122();
        $options = [];

        $rawOptions = $optionsByQuestion[$questionId] ?? null;
        if ($rawOptions !== null) {
            foreach ($rawOptions as $optionRow) {
                $options[] = new \App\DTO\QuestionPaperModule\QuestionOptionResponseDTO(
                    id: (string) $optionRow['id'],
                    text: $optionRow['optionText'],
                    displayOrder: $optionRow['displayOrder'],
                    isCorrect: (bool) ($optionRow['isCorrect'] ?? false),
                );
            }
        } else {
            foreach ($question->getOptions() as $option) {
                $options[] = new \App\DTO\QuestionPaperModule\QuestionOptionResponseDTO(
                    id: $option->getId()->toRfc4122(),
                    text: $option->getOptionText(),
                    displayOrder: $option->getDisplayOrder(),
                    isCorrect: $option->isCorrect(),
                );
            }
        }

        return new QuestionResponseDTO(
            id: $questionId,
            type: $question->getType()->value,
            questionText: $question->getQuestionText(),
            marks: $question->getMarks(),
            displayOrder: $question->getDisplayOrder(),
            image: $question->getImage(),
            options: $options,
            createdAt: $question->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $question->getUpdatedAt()?->format(DateTimeInterface::ATOM),
        );
    }

    private function renderPdfHtml(QuestionPaperPreviewResponseDTO $preview): string
    {
        $questionsHtml = '';
        foreach ($preview->questions as $question) {
            $questionsHtml .= '<div style="margin-bottom: 16px;">';
            $questionsHtml .= '<p><strong>' . $question->displayOrder . '. ' . htmlspecialchars($question->questionText) . ' [' . $question->marks . ' marks]</strong></p>';

            if ($question->type === 'MCQ' && !empty($question->options)) {
                $questionsHtml .= '<ul>';
                foreach ($question->options as $option) {
                    $questionsHtml .= '<li>' . htmlspecialchars($option->text) . '</li>';
                }
                $questionsHtml .= '</ul>';
            }

            $questionsHtml .= '</div>';
        }

        $classNames = implode(', ', $preview->classNames);
        $sectionNames = !empty($preview->sectionNames) ? implode(', ', $preview->sectionNames) : 'N/A';

        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>' . htmlspecialchars($preview->title) . '</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; margin: 40px; }
        h1 { text-align: center; margin-bottom: 8px; }
        .meta { text-align: center; margin-bottom: 24px; }
        .info { margin-bottom: 24px; }
        .instructions { margin-bottom: 24px; padding: 12px; border: 1px solid #ccc; }
        .question { margin-bottom: 16px; }
    </style>
</head>
<body>
    <h1>' . htmlspecialchars($preview->instituteName) . '</h1>
    <div class="meta">
        <h2>' . htmlspecialchars($preview->title) . '</h2>
        <p>Subject: ' . htmlspecialchars($preview->subjectName) . ' | Class: ' . htmlspecialchars($classNames) . ' | Section: ' . htmlspecialchars($sectionNames) . '</p>
        <p>Academic Year: ' . htmlspecialchars($preview->academicYearName) . ' | Date: ' . htmlspecialchars($preview->date) . ' | Duration: ' . $preview->duration . ' minutes | Max Marks: ' . $preview->maximumMarks . '</p>
    </div>
    ' . ($preview->instructions ? '<div class="instructions"><strong>Instructions:</strong><br>' . nl2br(htmlspecialchars($preview->instructions)) . '</div>' : '') . '
    <div class="questions">
        ' . $questionsHtml . '
    </div>
</body>
</html>';
    }
}
