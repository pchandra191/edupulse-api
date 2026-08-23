<?php
declare(strict_types=1);

namespace App\Controller\ExamSessionModule;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\Common\Api\Response\PaginationResponse;
use App\DTO\ExamSessionModule\CreateExamSessionRequestDTO;
use App\DTO\ExamSessionModule\ExamSessionFilterRequest;
use App\DTO\ExamSessionModule\ExamSessionResponseDTO;
use App\DTO\ExamSessionModule\UpdateExamSessionRequestDTO;
use App\DTO\Response\ExamSessionListResponse;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\ExamSessionModule\ExamSessionService;
use App\Service\Role\RolePermissionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ExamSessionController extends AbstractApiController
{
    public function __construct(
        private ExamSessionService $examSessionService,
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
    #[Route('/exam-sessions', name: 'exam_session_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->requirePermission('exam_sessions.view');

        $instituteId = $this->getCurrentInstituteId();

        $filter = new ExamSessionFilterRequest(
            search: $request->query->get('search'),
            questionPaperId: $request->query->get('questionPaperId'),
            examinationId: $request->query->get('examinationId'),
            status: $request->query->get('status'),
            page: max(1, (int) $request->query->get('page', 1)),
            limit: max(1, min(100, (int) $request->query->get('limit', 10))),
            sortBy: $request->query->get('sortBy', 'createdAt'),
            sortOrder: $request->query->get('sortOrder', 'DESC'),
        );

        $result = $this->examSessionService->getExamSessions($instituteId, $filter);

        return $this->paginated('Exam sessions retrieved successfully.', $result->items, new PaginationResponse(
            page: $result->pagination['page'],
            limit: $result->pagination['limit'],
            totalItems: $result->pagination['totalItems'],
            totalPages: $result->pagination['totalPages'],
        ), ['version' => 'v1']);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-sessions', name: 'exam_session_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->requirePermission('exam_sessions.create');

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $createRequest = new CreateExamSessionRequestDTO(
            questionPaperId: (string) ($data['questionPaperId'] ?? ''),
            examinationId: $data['examinationId'] ?? null,
            examSubjectId: $data['examSubjectId'] ?? null,
            title: (string) ($data['title'] ?? ''),
            instituteId: $data['instituteId'] ?? null,
            availableFrom: (string) ($data['availableFrom'] ?? ''),
            availableTo: (string) ($data['availableTo'] ?? ''),
            academicYearId: (string) ($data['academicYearId'] ?? ''),
            academicClassId: (string) ($data['academicClassId'] ?? ''),
            sectionIds: $data['sectionIds'] ?? [],
            durationOverride: $data['durationOverride'] ?? null,
            shuffleQuestions: (bool) ($data['shuffleQuestions'] ?? false),
            showResultsMode: (string) ($data['showResultsMode'] ?? 'IMMEDIATE'),
            accessMode: (string) ($data['accessMode'] ?? 'OPEN'),
            allowReview: (bool) ($data['allowReview'] ?? true),
            totalMarks: (int) ($data['totalMarks'] ?? 0),
            status: (string) ($data['status'] ?? 'DRAFT'),
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

                $instituteId = Uuid::fromString($createRequest->instituteId);
            }

            $examSession = $this->examSessionService->createExamSession($instituteId, $createRequest);

            return $this->created('Exam session created successfully.', $examSession);
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-sessions/{id}', name: 'exam_session_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $this->requirePermission('exam_sessions.view');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $examSession = $this->examSessionService->getExamSession($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->success('Exam session retrieved successfully.', $examSession);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-sessions/{id}', name: 'exam_session_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $this->requirePermission('exam_sessions.edit');

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $updateRequest = new UpdateExamSessionRequestDTO(
            title: $data['title'] ?? null,
            availableFrom: $data['availableFrom'] ?? null,
            availableTo: $data['availableTo'] ?? null,
            durationOverride: $data['durationOverride'] ?? null,
            shuffleQuestions: $data['shuffleQuestions'] ?? null,
            showResultsMode: $data['showResultsMode'] ?? null,
            accessMode: $data['accessMode'] ?? null,
            allowReview: $data['allowReview'] ?? null,
            totalMarks: $data['totalMarks'] ?? null,
            academicYearId: $data['academicYearId'] ?? null,
            academicClassId: $data['academicClassId'] ?? null,
            sectionIds: $data['sectionIds'] ?? null,
            status: $data['status'] ?? null,
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
            $examSession = $this->examSessionService->updateExamSession($this->parseUuid($id), $instituteId, $updateRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }

        return $this->updated('Exam session updated successfully.', $examSession);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-sessions/{id}', name: 'exam_session_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $this->requirePermission('exam_sessions.delete');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $this->examSessionService->deleteExamSession($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }

        return $this->deleted('Exam session deleted successfully.');
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-sessions/{id}/schedule', name: 'exam_session_schedule', methods: ['POST'])]
    public function schedule(string $id): JsonResponse
    {
        $this->requirePermission('exam_sessions.edit');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $examSession = $this->examSessionService->transitionExamSession($this->parseUuid($id), $instituteId, 'schedule');
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success('Exam session scheduled successfully.', $examSession);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-sessions/{id}/publish', name: 'exam_session_publish', methods: ['POST'])]
    public function publish(string $id): JsonResponse
    {
        $this->requirePermission('exam_sessions.edit');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $examSession = $this->examSessionService->transitionExamSession($this->parseUuid($id), $instituteId, 'publish');
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success('Exam session published successfully.', $examSession);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-sessions/{id}/start', name: 'exam_session_start', methods: ['POST'])]
    public function start(string $id): JsonResponse
    {
        $this->requirePermission('exam_sessions.edit');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $examSession = $this->examSessionService->transitionExamSession($this->parseUuid($id), $instituteId, 'start');
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success('Exam session started successfully.', $examSession);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-sessions/{id}/cancel', name: 'exam_session_cancel', methods: ['POST'])]
    public function cancel(string $id): JsonResponse
    {
        $this->requirePermission('exam_sessions.edit');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $examSession = $this->examSessionService->transitionExamSession($this->parseUuid($id), $instituteId, 'cancel');
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success('Exam session cancelled successfully.', $examSession);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-sessions/{id}/close', name: 'exam_session_close', methods: ['POST'])]
    public function close(string $id): JsonResponse
    {
        $this->requirePermission('exam_sessions.edit');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $examSession = $this->examSessionService->transitionExamSession($this->parseUuid($id), $instituteId, 'close');
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success('Exam session closed successfully.', $examSession);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-sessions/{id}/archive', name: 'exam_session_archive', methods: ['POST'])]
    public function archive(string $id): JsonResponse
    {
        $this->requirePermission('exam_sessions.edit');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $examSession = $this->examSessionService->transitionExamSession($this->parseUuid($id), $instituteId, 'archive');
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success('Exam session archived successfully.', $examSession);
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
            return Uuid::fromString($id);
        } catch (\Throwable $e) {
            throw new AuthenticationException('Invalid ID format.');
        }
    }
}
