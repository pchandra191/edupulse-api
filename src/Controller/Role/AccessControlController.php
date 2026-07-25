<?php
declare(strict_types=1);

namespace App\Controller\Role;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\Role\AccessControlMatrixResponse;
use App\DTO\Role\RolePermissionsUpdateRequest;
use App\Service\Role\RolePermissionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Uid\Uuid;

class AccessControlController extends AbstractApiController
{
    public function __construct(
        private RolePermissionService $rolePermissionService,
        private ValidatorInterface $validator,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/access-control', name: 'access_control_matrix', methods: ['GET'])]
    public function matrix(): JsonResponse
    {
        $matrix = $this->rolePermissionService->getAccessControlMatrix();

        return $this->success(data: $matrix);
    }

    #[Security("is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/access-control/{roleId}', name: 'access_control_update', methods: ['PUT'])]
    public function update(string $roleId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $updateRequest = new RolePermissionsUpdateRequest(
            permissions: $data['permissions'] ?? [],
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
            $permissions = $this->rolePermissionService->updateRolePermissions($this->parseUuid($roleId), $updateRequest);
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        return $this->updated('Role permissions updated successfully.', $permissions);
    }

    private function parseUuid(string $id): Uuid
    {
        try {
            return new Uuid($id);
        } catch (\InvalidArgumentException $e) {
            throw new \App\Common\Exception\BusinessException('Invalid role identifier.');
        }
    }
}
