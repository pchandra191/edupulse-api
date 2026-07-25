<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\RolePermission;
use App\Enum\RoleStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed:roles-permissions',
    description: 'Seed initial roles, permissions, and default mappings.',
)]
class SeedRolePermissionsCommand extends Command
{
    private const ROLE_DATA = [
        ['code' => 'ROLE_SUPER_ADMIN', 'name' => 'Super Admin', 'description' => 'Full system access'],
        ['code' => 'ROLE_INSTITUTE', 'name' => 'Institute', 'description' => 'Institute administrator'],
        ['code' => 'ROLE_TEACHER', 'name' => 'Teacher', 'description' => 'Teaching staff'],
        ['code' => 'ROLE_STUDENT', 'name' => 'Student', 'description' => 'Student'],
    ];

    private const PERMISSION_DATA = [
        ['group' => 'Dashboard', 'module' => 'dashboard', 'code' => 'dashboard.view', 'name' => 'View Dashboard', 'description' => 'Access the main dashboard overview.'],
        ['group' => 'Users', 'module' => 'users', 'code' => 'users.view', 'name' => 'View Users', 'description' => 'View the list of users.'],
        ['group' => 'Users', 'module' => 'users', 'code' => 'users.create', 'name' => 'Create Users', 'description' => 'Create new user accounts.'],
        ['group' => 'Users', 'module' => 'users', 'code' => 'users.edit', 'name' => 'Edit Users', 'description' => 'Modify existing user details.'],
        ['group' => 'Users', 'module' => 'users', 'code' => 'users.status', 'name' => 'Change User Status', 'description' => 'Activate or deactivate user accounts.'],
        ['group' => 'Users', 'module' => 'users', 'code' => 'users.reset_password', 'name' => 'Reset User Password', 'description' => 'Reset passwords for other users.'],
        ['group' => 'Students', 'module' => 'students', 'code' => 'students.view', 'name' => 'View Students', 'description' => 'View the student list and details.'],
        ['group' => 'Students', 'module' => 'students', 'code' => 'students.create', 'name' => 'Create Students', 'description' => 'Register new students.'],
        ['group' => 'Students', 'module' => 'students', 'code' => 'students.edit', 'name' => 'Edit Students', 'description' => 'Update student records.'],
        ['group' => 'Teachers', 'module' => 'teachers', 'code' => 'teachers.view', 'name' => 'View Teachers', 'description' => 'View the teacher list and details.'],
        ['group' => 'Teachers', 'module' => 'teachers', 'code' => 'teachers.create', 'name' => 'Create Teachers', 'description' => 'Add new teachers to the system.'],
        ['group' => 'Teachers', 'module' => 'teachers', 'code' => 'teachers.edit', 'name' => 'Edit Teachers', 'description' => 'Update teacher records.'],
        ['group' => 'Attendance', 'module' => 'attendance', 'code' => 'attendance.view', 'name' => 'View Attendance', 'description' => 'View attendance records.'],
        ['group' => 'Attendance', 'module' => 'attendance', 'code' => 'attendance.create', 'name' => 'Create Attendance', 'description' => 'Mark attendance for classes.'],
        ['group' => 'Attendance', 'module' => 'attendance', 'code' => 'attendance.edit', 'name' => 'Edit Attendance', 'description' => 'Modify attendance records.'],
        ['group' => 'Homework', 'module' => 'homework', 'code' => 'homework.view', 'name' => 'View Homework', 'description' => 'View assigned homework and submissions.'],
        ['group' => 'Homework', 'module' => 'homework', 'code' => 'homework.create', 'name' => 'Create Homework', 'description' => 'Create new homework assignments.'],
        ['group' => 'Homework', 'module' => 'homework', 'code' => 'homework.edit', 'name' => 'Edit Homework', 'description' => 'Modify existing homework.'],
        ['group' => 'Notice Board', 'module' => 'notice', 'code' => 'notice.view', 'name' => 'View Notices', 'description' => 'View published notices and announcements.'],
        ['group' => 'Notice Board', 'module' => 'notice', 'code' => 'notice.create', 'name' => 'Create Notices', 'description' => 'Publish new notices and announcements.'],
        ['group' => 'Notice Board', 'module' => 'notice', 'code' => 'notice.edit', 'name' => 'Edit Notices', 'description' => 'Update existing notices.'],
        ['group' => 'Exams', 'module' => 'exams', 'code' => 'exams.view', 'name' => 'View Exams', 'description' => 'View exam schedules and results.'],
        ['group' => 'Exams', 'module' => 'exams', 'code' => 'exams.create', 'name' => 'Create Exams', 'description' => 'Create new exam entries.'],
        ['group' => 'Exams', 'module' => 'exams', 'code' => 'exams.edit', 'name' => 'Edit Exams', 'description' => 'Update exam details.'],
        ['group' => 'Reports', 'module' => 'reports', 'code' => 'reports.view', 'name' => 'View Reports', 'description' => 'Access generated reports.'],
        ['group' => 'Reports', 'module' => 'reports', 'code' => 'reports.generate', 'name' => 'Generate Reports', 'description' => 'Generate new reports.'],
        ['group' => 'Settings', 'module' => 'settings', 'code' => 'settings.view', 'name' => 'View Settings', 'description' => 'View application settings.'],
        ['group' => 'Settings', 'module' => 'settings', 'code' => 'settings.edit', 'name' => 'Edit Settings', 'description' => 'Modify application settings.'],
        ['group' => 'Access Control', 'module' => 'access_control', 'code' => 'access_control.view', 'name' => 'View Access Control', 'description' => 'View role and permission configurations.'],
        ['group' => 'Access Control', 'module' => 'access_control', 'code' => 'access_control.edit', 'name' => 'Edit Access Control', 'description' => 'Modify role and permission assignments.'],
    ];

    private const DEFAULT_MAPPINGS = [
        'ROLE_SUPER_ADMIN' => null,
        'ROLE_INSTITUTE' => [
            'dashboard.view',
            'users.view', 'users.create', 'users.edit', 'users.status', 'users.reset_password',
            'students.view', 'students.create', 'students.edit',
            'teachers.view', 'teachers.create', 'teachers.edit',
            'attendance.view', 'attendance.create', 'attendance.edit',
            'homework.view', 'homework.create', 'homework.edit',
            'notice.view', 'notice.create', 'notice.edit',
            'exams.view', 'exams.create', 'exams.edit',
            'reports.view', 'reports.generate',
            'settings.view', 'settings.edit',
        ],
        'ROLE_TEACHER' => [
            'dashboard.view',
            'students.view',
            'attendance.view', 'attendance.create', 'attendance.edit',
            'homework.view', 'homework.create', 'homework.edit',
            'notice.view',
            'exams.view', 'exams.create', 'exams.edit',
            'reports.view',
        ],
        'ROLE_STUDENT' => [
            'dashboard.view',
            'notice.view',
            'homework.view',
            'attendance.view',
            'exams.view',
        ],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Seeding Roles & Permissions');

        $roles = $this->seedRoles();
        $permissions = $this->seedPermissions();
        $this->seedRolePermissions($roles, $permissions);

        $this->entityManager->flush();

        $this->logger->info('Role and permission seeding completed successfully.');

        $io->success('Roles, permissions, and mappings seeded successfully.');

        return Command::SUCCESS;
    }

    private function seedRoles(): array
    {
        $roles = [];

        foreach (self::ROLE_DATA as $data) {
            $role = $this->entityManager->getRepository(Role::class)->findByCode($data['code']);

            if (!$role) {
                $role = new Role();
                $role->setCode($data['code']);
                $role->setName($data['name']);
                $role->setDescription($data['description']);
                $role->setStatus(RoleStatus::Active);
                $this->entityManager->persist($role);

                $this->logger->info('Role created.', ['code' => $data['code']]);
            } else {
                $role->setName($data['name']);
                $role->setDescription($data['description']);
                $role->setStatus(RoleStatus::Active);

                $this->logger->info('Role already exists.', ['code' => $data['code']]);
            }

            $roles[$data['code']] = $role;
        }

        return $roles;
    }

    private function seedPermissions(): array
    {
        $permissions = [];

        foreach (self::PERMISSION_DATA as $data) {
            $permission = $this->entityManager->getRepository(Permission::class)->findByCode($data['code']);

            if (!$permission) {
                $permission = new Permission();
                $permission->setModule($data['module']);
                $permission->setGroupName($data['group']);
                $permission->setCode($data['code']);
                $permission->setName($data['name']);
                $permission->setDescription($data['description']);
                $this->entityManager->persist($permission);

                $this->logger->info('Permission created.', ['code' => $data['code']]);
            } else {
                $permission->setModule($data['module']);
                $permission->setGroupName($data['group']);
                $permission->setName($data['name']);
                $permission->setDescription($data['description']);

                $this->logger->info('Permission already exists.', ['code' => $data['code']]);
            }

            $permissions[$data['code']] = $permission;
        }

        return $permissions;
    }

    private function seedRolePermissions(array $roles, array $permissions): void
    {
        foreach (self::DEFAULT_MAPPINGS as $roleCode => $permissionCodes) {
            $role = $roles[$roleCode] ?? null;

            if (!$role) {
                continue;
            }

            $this->logger->info('Seeding role permissions.', ['role' => $roleCode]);

            $effectivePermissionCodes = $permissionCodes !== null ? $permissionCodes : array_keys($permissions);
            $this->syncRolePermissions($role, $effectivePermissionCodes, $permissions);
        }
    }

    private function syncRolePermissions(Role $role, array $desiredPermissionCodes, array $permissions): void
    {
        $existingRolePermissions = $this->entityManager->getRepository(RolePermission::class)->findBy(['role' => $role]);

        $existingPermissionCodes = [];
        foreach ($existingRolePermissions as $existingRolePermission) {
            $existingPermissionCodes[] = $existingRolePermission->getPermission()->getCode();
        }

        $desiredSet = array_flip($desiredPermissionCodes);

        foreach ($existingRolePermissions as $existingRolePermission) {
            $permissionCode = $existingRolePermission->getPermission()->getCode();

            if (!isset($desiredSet[$permissionCode])) {
                $this->entityManager->remove($existingRolePermission);
            }
        }

        foreach ($desiredPermissionCodes as $permissionCode) {
            if (in_array($permissionCode, $existingPermissionCodes, true)) {
                continue;
            }

            $permission = $permissions[$permissionCode] ?? null;

            if (!$permission) {
                continue;
            }

            $rolePermission = new RolePermission();
            $rolePermission->setRole($role);
            $rolePermission->setPermission($permission);
            $this->entityManager->persist($rolePermission);
        }

        $this->logger->info('Role permissions synchronized.', ['role' => $role->getCode(), 'count' => count($desiredPermissionCodes)]);
    }
}
