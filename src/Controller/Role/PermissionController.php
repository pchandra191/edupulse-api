<?php
declare(strict_types=1);

namespace App\Controller\Role;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\Role\PermissionGroupResponse;
use App\Service\Role\RolePermissionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;

class PermissionController extends AbstractApiController
{
    public function __construct(
        private RolePermissionService $rolePermissionService,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/permissions', name: 'permission_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $groups = $this->rolePermissionService->getGroupedPermissions();

        return $this->success(data: $groups);
    }
}
