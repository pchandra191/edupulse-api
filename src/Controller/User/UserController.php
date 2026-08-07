<?php
declare(strict_types=1);

namespace App\Controller\User;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\User\AdminResetPasswordRequestDTO;
use App\DTO\User\CreateUserRequest;
use App\DTO\User\UpdateUserRequest;
use App\DTO\User\UserFilterRequest;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\AuthenticationException;
use App\Common\Exception\BusinessException;
use App\Service\User\UserService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Uid\Uuid;

class UserController extends AbstractApiController
{
    public function __construct(
        private UserService $userService,
        private ValidatorInterface $validator,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/users', name: 'user_list', methods: ['GET'])]
    public function list(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $filter = new UserFilterRequest(
            search: $request->query->get('search'),
            role: $request->query->get('role'),
            status: $request->query->get('status'),
            department: $request->query->get('department'),
            page: max(1, (int) $request->query->get('page', 1)),
            limit: max(1, min(100, (int) $request->query->get('limit', 10))),
            sortBy: $request->query->get('sortBy', 'firstName'),
            sortOrder: $request->query->get('sortOrder', 'ASC'),
        );

        $result = $this->userService->getUsers($filter);

        return $this->success(data: $result);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/users/{id}', name: 'user_get', methods: ['GET'])]
    public function get(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->userService->getUser($this->parseUuid($id));
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->success(data: $user);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/users', name: 'user_create', methods: ['POST'])]
    public function create(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $roles = $data['roles'] ?? [UserRole::Student->value];
        if (!is_array($roles)) {
            $roles = [$roles];
        }

        $createRequest = new CreateUserRequest(
            firstName: $data['firstName'] ?? '',
            lastName: $data['lastName'] ?? '',
            email: $data['email'] ?? '',
            phone: $data['phone'] ?? null,
            department: $data['department'] ?? null,
            roles: $roles,
            password: $data['password'] ?? '',
            instituteName: $data['instituteName'] ?? null,
            instituteId: $data['instituteId'] ?? null,
        );

        $errors = $this->validator->validate($createRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->error('Validation failed.', $formattedErrors);
        }

        $user = $this->userService->createUser($createRequest);

        return $this->created('User created successfully.', $user);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/users/{id}', name: 'user_update', methods: ['PUT'])]
    public function update(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $roles = null;
        if (isset($data['roles'])) {
            $roles = is_array($data['roles']) ? $data['roles'] : [$data['roles']];
        }

        $updateRequest = new UpdateUserRequest(
            email: $data['email'] ?? null,
            firstName: $data['firstName'] ?? null,
            lastName: $data['lastName'] ?? null,
            phone: $data['phone'] ?? null,
            department: $data['department'] ?? null,
            roles: $roles,
            status: isset($data['status']) ? UserStatus::from((string) $data['status']) : null,
        );

        $errors = $this->validator->validate($updateRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->error('Validation failed.', $formattedErrors);
        }

        try {
            $user = $this->userService->updateUser($this->parseUuid($id), $updateRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (BusinessException $e) {
            return $this->error($e->getMessage());
        }

        return $this->updated('User updated successfully.', $user);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/users/{id}/status', name: 'user_update_status', methods: ['PATCH'])]
    public function updateStatus(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || !isset($data['status'])) {
            return $this->error('Status is required.');
        }

        try {
            $status = UserStatus::from((string) $data['status']);
        } catch (\ValueError $e) {
            return $this->error('Invalid status value. Allowed: ACTIVE, INACTIVE.');
        }

        $user = $this->userService->changeStatus($this->parseUuid($id), $status);

        return $this->updated('User status updated successfully.', $user);
    }

    #[Security("is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/users/{id}/reset-password', name: 'user_reset_password', methods: ['POST'])]
    public function resetPassword(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $resetRequest = new AdminResetPasswordRequestDTO(
            newPassword: $data['newPassword'] ?? '',
            confirmPassword: $data['confirmPassword'] ?? '',
        );

        $errors = $this->validator->validate($resetRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->error('Validation failed.', $formattedErrors);
        }

        $this->userService->resetPassword($this->parseUuid($id), $resetRequest);

        return $this->success('Password reset successfully.');
    }

    private function parseUuid(string $id): Uuid
    {
        try {
            return new Uuid($id);
        } catch (\InvalidArgumentException $e) {
            throw new AuthenticationException('User not found.', previous: $e);
        }
    }
}
