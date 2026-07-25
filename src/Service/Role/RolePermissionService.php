<?php
declare(strict_types=1);

namespace App\Service\Role;

use App\Common\Exception\BusinessException;
use App\Common\Exception\EntityNotFoundException;
use App\DTO\Role\AccessControlMatrixResponse;
use App\DTO\Role\PermissionGroupResponse;
use App\DTO\Role\PermissionResponse;
use App\DTO\Role\RolePermissionsUpdateRequest;
use App\DTO\Role\RoleResponse;
use App\DTO\Role\UserPermissionsResponse;
use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\RolePermission;
use App\Entity\User;
use App\Repository\PermissionRepository;
use App\Repository\RolePermissionRepository;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

readonly class RolePermissionService
{
    public function __construct(
        private RoleRepository $roleRepository,
        private PermissionRepository $permissionRepository,
        private RolePermissionRepository $rolePermissionRepository,
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function getAllRoles(): array
    {
        $roles = $this->roleRepository->findAllActive();

        return array_map(
            fn(Role $role) => new RoleResponse(
                id: (string) $role->getId(),
                code: $role->getCode(),
                name: $role->getName(),
                description: $role->getDescription(),
                status: $role->getStatus(),
            ),
            $roles,
        );
    }

    public function getGroupedPermissions(): array
    {
        $permissions = $this->permissionRepository->findAllOrdered();

        $groups = [];
        foreach ($permissions as $permission) {
            $groupName = $permission->getGroupName();
            if (!isset($groups[$groupName])) {
                $groups[$groupName] = [];
            }
            $groups[$groupName][] = $permission;
        }

        $result = [];
        foreach ($groups as $groupName => $groupPermissions) {
            $result[] = new PermissionGroupResponse(
                group: $groupName,
                permissions: array_map(
                    fn(Permission $p) => new PermissionResponse(
                        module: $p->getModule(),
                        groupName: $p->getGroupName(),
                        code: $p->getCode(),
                        name: $p->getName(),
                        description: $p->getDescription(),
                    ),
                    $groupPermissions,
                ),
            );
        }

        return $result;
    }

    public function getAccessControlMatrix(): array
    {
        $roles = $this->roleRepository->findAllActive();

        $result = [];
        foreach ($roles as $role) {
            $permissionCodes = $this->rolePermissionRepository->findPermissionsByRoleCodes([$role->getCode()]);

            $result[] = new AccessControlMatrixResponse(
                role: $role->getCode(),
                permissions: $permissionCodes,
            );
        }

        return $result;
    }

    public function updateRolePermissions(Uuid $roleId, RolePermissionsUpdateRequest $request): array
    {
        $role = $this->roleRepository->findByUuid($roleId);

        if (!$role instanceof Role) {
            throw new BusinessException(sprintf('Role with identifier "%s" not found.', $roleId));
        }

        $validPermissions = [];
        if (!empty($request->permissions)) {
            $permissionEntities = $this->permissionRepository->findByCodes($request->permissions);
            $validPermissions = $permissionEntities;
            $foundCodes = array_map(fn(Permission $p) => $p->getCode(), $permissionEntities);
            $missingCodes = array_diff($request->permissions, $foundCodes);

            if (!empty($missingCodes)) {
                throw new BusinessException(sprintf('Unknown permissions: %s', implode(', ', $missingCodes)));
            }
        }

        $this->rolePermissionRepository->deleteByRoleUuid($roleId);

        foreach ($validPermissions as $permission) {
            $rolePermission = new RolePermission();
            $rolePermission->setRole($role);
            $rolePermission->setPermission($permission);
            $this->entityManager->persist($rolePermission);
        }

        $this->entityManager->flush();

        $this->logger->info('Role permissions updated', [
            'role_id' => (string) $roleId,
            'permissions_count' => count($validPermissions),
        ]);

        $updatedPermissions = $this->rolePermissionRepository->findPermissionsByRoleCodes([$role->getCode()]);

        return $updatedPermissions;
    }

    public function getUserEffectivePermissions(Uuid $userId): UserPermissionsResponse
    {
        $user = $this->userRepository->findByUuid($userId);

        if (!$user instanceof User) {
            throw EntityNotFoundException::forEntity('User', (string) $userId);
        }

        $roleCodes = $user->getRoles();

        if (empty($roleCodes)) {
            return new UserPermissionsResponse(
                role: '',
                permissions: [],
            );
        }

        $permissions = $this->rolePermissionRepository->findPermissionsByRoleCodes($roleCodes);

        return new UserPermissionsResponse(
            role: implode(', ', $roleCodes),
            permissions: $permissions,
        );
    }
}
