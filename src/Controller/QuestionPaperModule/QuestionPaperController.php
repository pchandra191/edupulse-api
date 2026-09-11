<?php
declare(strict_types=1);

namespace App\Controller\QuestionPaperModule;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\QuestionPaperModule\CreateQuestionPaperRequestDTO;
use App\DTO\QuestionPaperModule\CreateQuestionRequestDTO;
use App\DTO\QuestionPaperModule\GenerateAiQuestionRequestDTO;
use App\DTO\QuestionPaperModule\QuestionPaperFilterRequest;
use App\DTO\QuestionPaperModule\QuestionPaperPreviewResponseDTO;
use App\DTO\QuestionPaperModule\QuestionPaperResponseDTO;
use App\DTO\QuestionPaperModule\UpdateQuestionPaperRequestDTO;
use App\DTO\QuestionPaperModule\UpdateQuestionRequestDTO;
use App\DTO\Response\QuestionPaperListResponse;
use App\Common\Api\Response\PaginationResponse;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\QuestionPaperModule\QuestionPaperPdfService;
use App\Service\QuestionPaperModule\QuestionPaperService;
use App\Service\QuestionPaperModule\QuestionImageService;
use App\Service\Role\RolePermissionService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class QuestionPaperController extends AbstractApiController
{
    public function __construct(
        private QuestionPaperService $questionPaperService,
        private QuestionPaperPdfService $questionPaperPdfService,
        private QuestionImageService $questionImageService,
        private RolePermissionService $rolePermissionService,
        private ValidatorInterface $validator,
        private Security $security,
        private ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    private function normalizePermissionCode(string $code): string
    {
        return str_replace('-', '_', mb_strtolower($code));
    }

    private function hasPermission(string $permissionCode): bool
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        $effectivePermissions = $this->rolePermissionService->getUserEffectivePermissions($user->getId());

        return in_array($this->normalizePermissionCode($permissionCode), $effectivePermissions->permissions, true);
    }

    private function requirePermission(string $permissionCode): void
    {
        if (!$this->hasPermission($permissionCode)) {
            throw new AuthenticationException('Access denied.');
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers', name: 'question_paper_list', methods: ['GET'])]
    public function list(\Symfony\Component\HttpFoundation\Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requirePermission('question-papers.view');

        $instituteId = $this->getCurrentInstituteId();

        $filter = new QuestionPaperFilterRequest(
            search: $request->query->get('search'),
            academicYearId: $request->query->get('academicYearId'),
            subjectId: $request->query->get('subjectId'),
            examinationId: $request->query->get('examinationId'),
            status: $request->query->get('status'),
            page: max(1, (int) $request->query->get('page', 1)),
            limit: max(1, min(100, (int) $request->query->get('limit', 10))),
            sortBy: $request->query->get('sortBy', 'createdAt'),
            sortOrder: $request->query->get('sortOrder', 'DESC'),
        );

        $result = $this->questionPaperService->getQuestionPapers($instituteId, $filter);

        return $this->paginated('Question papers retrieved successfully.', $result->items, new PaginationResponse(
            page: $result->pagination['page'],
            limit: $result->pagination['limit'],
            totalItems: $result->pagination['totalItems'],
            totalPages: $result->pagination['totalPages'],
        ), ['version' => 'v1']);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers', name: 'question_paper_create', methods: ['POST'])]
    public function create(\Symfony\Component\HttpFoundation\Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requirePermission('question-papers.create');

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $createRequest = new CreateQuestionPaperRequestDTO(
            academicYearId: (string) ($data['academicYearId'] ?? ''),
            subjectId: (string) ($data['subjectId'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            date: (string) ($data['date'] ?? ''),
            duration: (int) ($data['duration'] ?? 0),
            maximumMarks: (int) ($data['maximumMarks'] ?? 0),
            status: (string) ($data['status'] ?? 'DRAFT'),
            instructions: $data['instructions'] ?? null,
            instituteId: (string) ($data['instituteId'] ?? ''),
            classIds: $data['classIds'] ?? [],
            sectionIds: $data['sectionIds'] ?? [],
            examinationId: $data['examinationId'] ?? null,
            createdBy: $data['createdBy'] ?? null,
        );

        $errors = $this->validator->validate($createRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $instituteId = $this->getCurrentInstituteId();

            if ($instituteId === null) {
                if (!$createRequest->instituteId) {
                    return $this->unprocessableEntity('Validation failed.', ['instituteId' => ['Institute ID is required.']]);
                }

                try {
                    $instituteId = new Uuid($createRequest->instituteId);
                } catch (\InvalidArgumentException $e) {
                    return $this->unprocessableEntity('Validation failed.', ['instituteId' => ['Invalid UUID format.']]);
                }
            }

            $paper = $this->questionPaperService->createQuestionPaper($instituteId, $createRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->created('Question paper created successfully.', $paper);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers/{id}/clone', name: 'question_paper_clone', methods: ['POST'])]
    public function clone(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requirePermission('question-papers.create');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $paper = $this->questionPaperService->cloneQuestionPaper($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->created('Question paper cloned successfully.', $paper);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers/{id}', name: 'question_paper_get', methods: ['GET'])]
    public function get(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requirePermission('question-papers.view');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $paper = $this->questionPaperService->getQuestionPaper($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->success('Question paper retrieved successfully.', $paper);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers/{id}', name: 'question_paper_update', methods: ['PUT'])]
    public function update(string $id, \Symfony\Component\HttpFoundation\Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requirePermission('question-papers.edit');

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $updateRequest = new UpdateQuestionPaperRequestDTO(
            title: $data['title'] ?? null,
            date: $data['date'] ?? null,
            duration: $data['duration'] ?? null,
            maximumMarks: $data['maximumMarks'] ?? null,
            instructions: $data['instructions'] ?? null,
            status: isset($data['status']) ? strtoupper((string) $data['status']) : null,
            classIds: $data['classIds'] ?? null,
            sectionIds: $data['sectionIds'] ?? null,
            examinationId: $data['examinationId'] ?? null,
        );

        $errors = $this->validator->validate($updateRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $instituteId = $this->getCurrentInstituteId();
            $paper = $this->questionPaperService->updateQuestionPaper($this->parseUuid($id), $instituteId, $updateRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->updated('Question paper updated successfully.', $paper);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers/{id}', name: 'question_paper_delete', methods: ['DELETE'])]
    public function delete(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requirePermission('question-papers.delete');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $this->questionPaperService->deleteQuestionPaper($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->deleted('Question paper archived successfully.');
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers/{id}/finalize', name: 'question_paper_finalize', methods: ['POST'])]
    public function finalize(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requirePermission('question-papers.finalize');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $paper = $this->questionPaperService->finalizeQuestionPaper($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->success('Question paper finalized successfully.', $paper);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers/{id}/submit-for-review', name: 'question_paper_submit_for_review', methods: ['POST'])]
    public function submitForReview(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requirePermission('question-papers.edit');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $paper = $this->questionPaperService->submitForReview($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->success('Question paper submitted for review.', $paper);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers/{id}/request-changes', name: 'question_paper_request_changes', methods: ['POST'])]
    public function requestChanges(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requirePermission('question-papers.edit');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $paper = $this->questionPaperService->requestChanges($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->success('Question paper sent back for changes.', $paper);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers/{id}/preview', name: 'question_paper_preview', methods: ['GET'])]
    public function preview(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requirePermission('question-papers.view');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $preview = $this->questionPaperService->getPreview($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->success('Preview retrieved successfully.', $preview);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers/{id}/pdf', name: 'question_paper_pdf', methods: ['GET'])]
    public function pdf(string $id): \Symfony\Component\HttpFoundation\Response
    {
        $this->requirePermission('question-papers.export');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $pdfContent = $this->questionPaperPdfService->generatePdf(
                $this->questionPaperService->getPreview($this->parseUuid($id), $instituteId)
            );
        } catch (AuthenticationException $e) {
            return new Response('Question paper not found.', 404);
        } catch (\Throwable $e) {
            return new Response('Failed to generate PDF.', 500);
        }

        $response = new Response($pdfContent);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="question-paper.pdf"');

        return $response;
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers/{paperId}/questions', name: 'question_list', methods: ['GET'])]
    public function listQuestions(string $paperId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requirePermission('question-papers.view');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $questions = $this->questionPaperService->getQuestions($this->parseUuid($paperId), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->success('Questions retrieved successfully.', $questions);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers/{paperId}/questions', name: 'question_create', methods: ['POST'])]
    public function createQuestion(string $paperId, \Symfony\Component\HttpFoundation\Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requirePermission('question-papers.create');

        $data = $this->decodeRequestPayload($request);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $uploadedFile = $request->files->get('image');

        $createRequest = new CreateQuestionRequestDTO(
            type: (string) ($data['type'] ?? ''),
            questionText: (string) ($data['questionText'] ?? ''),
            marks: (int) ($data['marks'] ?? 0),
            displayOrder: (int) ($data['displayOrder'] ?? 0),
            image: $data['image'] ?? null,
            imageUrl: $data['imageUrl'] ?? null,
            imageFileReference: null,
            referenceAnswer: $data['referenceAnswer'] ?? null,
            options: $data['options'] ?? [],
        );

        $errors = $this->validator->validate($createRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        if ($uploadedFile !== null && ($data['image'] ?? null) !== null) {
            return $this->unprocessableEntity('Provide only one image source (imageUrl or uploaded file).');
        }

        if (array_key_exists('imageFileReference', $data)) {
            return $this->unprocessableEntity('imageFileReference is set by the file upload endpoint, not the request body.');
        }

        try {
            $instituteId = $this->getCurrentInstituteId();
            $question = $this->questionPaperService->createQuestion(
                $this->parseUuid($paperId),
                $instituteId,
                $createRequest,
                $uploadedFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile ? $uploadedFile : null,
            );
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        } catch (\Throwable $e) {
            throw $e;
        }

        return $this->created('Question created successfully.', $question);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers/{paperId}/questions/{questionId}', name: 'question_get', methods: ['GET'])]
    public function getQuestion(string $paperId, string $questionId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requirePermission('question-papers.view');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $question = $this->questionPaperService->getQuestion($this->parseUuid($paperId), $this->parseUuid($questionId), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->success('Question retrieved successfully.', $question);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers/{paperId}/questions/{questionId}', name: 'question_update', methods: ['PUT'])]
    public function updateQuestion(string $paperId, string $questionId, \Symfony\Component\HttpFoundation\Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requirePermission('question-papers.edit');

        $data = $this->decodeRequestPayload($request);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $uploadedFile = $request->files->get('image');

        $updateRequest = new UpdateQuestionRequestDTO(
            type: $data['type'] ?? null,
            questionText: $data['questionText'] ?? null,
            marks: isset($data['marks']) ? (int) $data['marks'] : null,
            displayOrder: isset($data['displayOrder']) ? (int) $data['displayOrder'] : null,
            image: $data['image'] ?? null,
            imageUrl: $data['imageUrl'] ?? null,
            imageFileReference: null,
            referenceAnswer: $data['referenceAnswer'] ?? null,
            options: $data['options'] ?? null,
        );

        $errors = $this->validator->validate($updateRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        if ($uploadedFile !== null && ($data['image'] ?? null) !== null) {
            return $this->unprocessableEntity('Provide only one image source (imageUrl or uploaded file).');
        }

        if (array_key_exists('imageFileReference', $data)) {
            return $this->unprocessableEntity('imageFileReference is set by the file upload endpoint, not the request body.');
        }

        try {
            $instituteId = $this->getCurrentInstituteId();
            $question = $this->questionPaperService->updateQuestion(
                $this->parseUuid($paperId),
                $this->parseUuid($questionId),
                $instituteId,
                $updateRequest,
                $uploadedFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile ? $uploadedFile : null,
            );
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->updated('Question updated successfully.', $question);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers/{paperId}/questions/{questionId}', name: 'question_delete', methods: ['DELETE'])]
    public function deleteQuestion(string $paperId, string $questionId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requirePermission('question-papers.delete');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $this->questionPaperService->deleteQuestion($this->parseUuid($paperId), $this->parseUuid($questionId), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->deleted('Question deleted successfully.');
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers/{paperId}/questions/reorder', name: 'question_reorder', methods: ['POST'])]
    public function reorderQuestions(string $paperId, \Symfony\Component\HttpFoundation\Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requirePermission('question-papers.edit');

        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || !isset($data['orderedIds']) || !is_array($data['orderedIds'])) {
            return $this->error('orderedIds array is required.');
        }

        try {
            $instituteId = $this->getCurrentInstituteId();
            $questions = $this->questionPaperService->reorderQuestions($this->parseUuid($paperId), $instituteId, $data['orderedIds']);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success('Questions reordered successfully.', $questions);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers/{paperId}/questions/generate', name: 'question_generate_ai', methods: ['POST'])]
    public function generateAiQuestion(string $paperId, \Symfony\Component\HttpFoundation\Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requirePermission('question-papers.create');

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $generateRequest = new GenerateAiQuestionRequestDTO(
            type: (string) ($data['type'] ?? ''),
            topic: (string) ($data['topic'] ?? ''),
            difficulty: (string) ($data['difficulty'] ?? 'MEDIUM'),
            marks: (int) ($data['marks'] ?? 1),
            instruction: $data['instruction'] ?? null,
        );

        $errors = $this->validator->validate($generateRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $instituteId = $this->getCurrentInstituteId();
            $generated = $this->questionPaperService->generateAiQuestion($this->parseUuid($paperId), $instituteId, $generateRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->success('Question generated successfully.', $generated);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers/{paperId}/questions/{questionId}/image', name: 'question_image_upload', methods: ['POST'])]
    public function uploadQuestionImage(string $paperId, string $questionId, \Symfony\Component\HttpFoundation\Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requirePermission('question-papers.edit');

        $uploadedFile = $request->files->get('image');

        if (!$uploadedFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            return $this->unprocessableEntity('Image file is required.', ['image' => ['Image file is required.']]);
        }

        try {
            $instituteId = $this->getCurrentInstituteId();
            $question = $this->questionPaperService->replaceQuestionImage(
                $this->parseUuid($paperId),
                $this->parseUuid($questionId),
                $instituteId,
                $uploadedFile
            );
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->success('Question image uploaded successfully.', $question);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/question-papers/{paperId}/questions/{questionId}/image', name: 'question_image_serve', methods: ['GET'])]
    public function serveQuestionImage(string $paperId, string $questionId): BinaryFileResponse|Response
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $reference = $this->questionPaperService->getQuestionImageReference(
                $this->parseUuid($paperId),
                $this->parseUuid($questionId),
                $instituteId
            );
        } catch (AuthenticationException $e) {
            return new Response('Question not found.', Response::HTTP_NOT_FOUND);
        }

        return $this->questionImageService->buildFileResponse($reference);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/question-papers/{paperId}/questions/{questionId}/image', name: 'question_image_delete', methods: ['DELETE'])]
    public function removeQuestionImage(string $paperId, string $questionId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->requirePermission('question-papers.edit');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $question = $this->questionPaperService->clearQuestionImage(
                $this->parseUuid($paperId),
                $this->parseUuid($questionId),
                $instituteId
            );
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->success('Question image removed successfully.', $question);
    }

    private function getCurrentInstituteId(): ?Uuid
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return null;
        }

        $institute = $user->getInstitute();

        if (!$institute) {
            throw new AuthenticationException('Institute not found.');
        }

        return $institute->getId();
    }

    private function parseUuid(string $id): Uuid
    {
        try {
            return new Uuid($id);
        } catch (\InvalidArgumentException $e) {
            throw new AuthenticationException('Question paper not found.', previous: $e);
        }
    }

    private function decodeRequestPayload(\Symfony\Component\HttpFoundation\Request $request): array
    {
        $contentType = (string) $request->headers->get('Content-Type', '');
        if (str_contains($contentType, 'multipart/form-data')) {
            $data = $request->request->all();

            return is_array($data) ? $data : [];
        }

        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
