<?php
declare(strict_types=1);

namespace App\Controller\Role;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\Role\UserPermissionsResponse;
use App\Service\Role\RolePermissionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Component\Uid\Uuid;

class UserPermissionController extends AbstractApiController
{
    public function __construct(
        private RolePermissionService $rolePermissionService,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/users/{id}/permissions', name: 'user_permissions', methods: ['GET'])]
    public function getUserPermissions(string $id): JsonResponse
    {
        try {
            $permissions = $this->rolePermissionService->getUserEffectivePermissions(new Uuid($id));
        } catch (\App\Common\Exception\EntityNotFoundException $e) {
            return $this->notFound();
        }

        return $this->success(data: $permissions);
    }
}
