<?php
declare(strict_types=1);

namespace App\Controller\ExamAttemptModule;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\Common\Api\Response\PaginationResponse;
use App\DTO\ExamAttemptModule\CreateExamAttemptRequestDTO;
use App\DTO\ExamAttemptModule\ExamAttemptFilterRequest;
use App\DTO\ExamAttemptModule\ExamAttemptResponseDTO;
use App\DTO\Response\ExamAttemptListResponse;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\ExamAttemptModule\ExamAttemptService;
use App\Service\Role\RolePermissionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ExamAttemptController extends AbstractApiController
{
    public function __construct(
        private ExamAttemptService $examAttemptService,
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

        if (in_array('ROLE_STUDENT', $user->getRoles(), true)) {
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

    #[SecurityAttribute("is_granted('ROLE_STUDENT') or is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-sessions/{id}/attempts', name: 'exam_attempt_start', methods: ['POST'])]
    public function start(string $id): JsonResponse
    {
        $this->requirePermission('exam_attempts.start');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $attempt = $this->examAttemptService->startAttempt($this->parseUuid($id));
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success('Exam attempt started successfully.', $attempt);
    }

    #[SecurityAttribute("is_granted('ROLE_STUDENT') or is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-attempts/{id}', name: 'exam_attempt_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $this->requirePermission('exam_attempts.view');

        try {
            $instituteId = $this->getCurrentInstituteId();
            $attempt = $this->examAttemptService->getAttempt($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->success('Exam attempt retrieved successfully.', $attempt);
    }

    #[SecurityAttribute("is_granted('ROLE_STUDENT') or is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-attempts/{id}/submit', name: 'exam_attempt_submit', methods: ['POST'])]
    public function submit(string $id): JsonResponse
    {
        $this->requirePermission('exam_attempts.submit');

        try {
            $attempt = $this->examAttemptService->submitAttempt($this->parseUuid($id));
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success('Exam attempt submitted successfully.', $attempt);
    }

    #[SecurityAttribute("is_granted('ROLE_STUDENT') or is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-attempts/me', name: 'exam_attempt_my_list', methods: ['GET'])]
    public function myList(Request $request): JsonResponse
    {
        $this->requirePermission('exam_attempts.view');

        $instituteId = $this->getCurrentInstituteId();

        $filter = new ExamAttemptFilterRequest(
            search: $request->query->get('search'),
            status: $request->query->get('status'),
            page: max(1, (int) $request->query->get('page', 1)),
            limit: max(1, min(100, (int) $request->query->get('limit', 10))),
            sortBy: $request->query->get('sortBy', 'createdAt'),
            sortOrder: $request->query->get('sortOrder', 'DESC'),
        );

        $result = $this->examAttemptService->getMyAttempts($instituteId, $filter);

        return $this->paginated('Exam attempts retrieved successfully.', $result->items, new PaginationResponse(
            page: $result->pagination['page'],
            limit: $result->pagination['limit'],
            totalItems: $result->pagination['totalItems'],
            totalPages: $result->pagination['totalPages'],
        ), ['version' => 'v1']);
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
