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
        ['group' => 'Users', 'module' => 'users', 'code' => 'users.delete', 'name' => 'Delete Users', 'description' => 'Remove user accounts.'],
        ['group' => 'Users', 'module' => 'users', 'code' => 'users.status', 'name' => 'Change User Status', 'description' => 'Activate or deactivate user accounts.'],
        ['group' => 'Users', 'module' => 'users', 'code' => 'users.reset_password', 'name' => 'Reset User Password', 'description' => 'Reset passwords for other users.'],
        ['group' => 'Users', 'module' => 'users', 'code' => 'users.manage_permissions', 'name' => 'Manage User Permissions', 'description' => 'Assign or remove permissions for users.'],
        ['group' => 'Students', 'module' => 'students', 'code' => 'students.view', 'name' => 'View Students', 'description' => 'View the student list and details.'],
        ['group' => 'Students', 'module' => 'students', 'code' => 'students.create', 'name' => 'Create Students', 'description' => 'Register new students.'],
        ['group' => 'Students', 'module' => 'students', 'code' => 'students.edit', 'name' => 'Edit Students', 'description' => 'Update student records.'],
        ['group' => 'Students', 'module' => 'students', 'code' => 'students.delete', 'name' => 'Delete Students', 'description' => 'Remove student records.'],
        ['group' => 'Students', 'module' => 'students', 'code' => 'students.import', 'name' => 'Import Students', 'description' => 'Import student records in bulk.'],
        ['group' => 'Students', 'module' => 'students', 'code' => 'students.export', 'name' => 'Export Students', 'description' => 'Export student records.'],
        ['group' => 'Teachers', 'module' => 'teachers', 'code' => 'teachers.view', 'name' => 'View Teachers', 'description' => 'View the teacher list and details.'],
        ['group' => 'Teachers', 'module' => 'teachers', 'code' => 'teachers.create', 'name' => 'Create Teachers', 'description' => 'Add new teachers to the system.'],
        ['group' => 'Teachers', 'module' => 'teachers', 'code' => 'teachers.edit', 'name' => 'Edit Teachers', 'description' => 'Update teacher records.'],
        ['group' => 'Teachers', 'module' => 'teachers', 'code' => 'teachers.delete', 'name' => 'Delete Teachers', 'description' => 'Remove teacher records.'],
        ['group' => 'Teacher Assignments', 'module' => 'teacher-assignments', 'code' => 'teacher-assignments.view', 'name' => 'View Teacher Assignments', 'description' => 'View teacher class assignments.'],
        ['group' => 'Teacher Assignments', 'module' => 'teacher-assignments', 'code' => 'teacher-assignments.create', 'name' => 'Create Teacher Assignments', 'description' => 'Assign teachers to classes.'],
        ['group' => 'Teacher Assignments', 'module' => 'teacher-assignments', 'code' => 'teacher-assignments.edit', 'name' => 'Edit Teacher Assignments', 'description' => 'Modify teacher class assignments.'],
        ['group' => 'Teacher Assignments', 'module' => 'teacher-assignments', 'code' => 'teacher-assignments.delete', 'name' => 'Delete Teacher Assignments', 'description' => 'Remove teacher class assignments.'],
        ['group' => 'Student Enrollments', 'module' => 'student-enrollments', 'code' => 'student-enrollments.view', 'name' => 'View Student Enrollments', 'description' => 'View student enrollment records.'],
        ['group' => 'Student Enrollments', 'module' => 'student-enrollments', 'code' => 'student-enrollments.create', 'name' => 'Create Student Enrollments', 'description' => 'Enroll students in classes.'],
        ['group' => 'Student Enrollments', 'module' => 'student-enrollments', 'code' => 'student-enrollments.edit', 'name' => 'Edit Student Enrollments', 'description' => 'Modify student enrollment records.'],
        ['group' => 'Student Enrollments', 'module' => 'student-enrollments', 'code' => 'student-enrollments.delete', 'name' => 'Delete Student Enrollments', 'description' => 'Remove student enrollment records.'],
        ['group' => 'Attendance', 'module' => 'attendance', 'code' => 'attendance.view', 'name' => 'View Attendance', 'description' => 'View attendance records.'],
        ['group' => 'Attendance', 'module' => 'attendance', 'code' => 'attendance.mark', 'name' => 'Mark Attendance', 'description' => 'Mark student attendance.'],
        ['group' => 'Attendance', 'module' => 'attendance', 'code' => 'attendance.edit', 'name' => 'Edit Attendance', 'description' => 'Modify attendance records.'],
        ['group' => 'Homework', 'module' => 'homework', 'code' => 'homework.view', 'name' => 'View Homework', 'description' => 'View assigned homework and submissions.'],
        ['group' => 'Homework', 'module' => 'homework', 'code' => 'homework.create', 'name' => 'Create Homework', 'description' => 'Create new homework assignments.'],
        ['group' => 'Homework', 'module' => 'homework', 'code' => 'homework.edit', 'name' => 'Edit Homework', 'description' => 'Modify existing homework.'],
        ['group' => 'Homework', 'module' => 'homework', 'code' => 'homework.delete', 'name' => 'Delete Homework', 'description' => 'Remove homework assignments.'],
        ['group' => 'Notice Board', 'module' => 'notice', 'code' => 'notice.view', 'name' => 'View Notices', 'description' => 'View published notices and announcements.'],
        ['group' => 'Notice Board', 'module' => 'notice', 'code' => 'notice.create', 'name' => 'Create Notices', 'description' => 'Publish new notices and announcements.'],
        ['group' => 'Notice Board', 'module' => 'notice', 'code' => 'notice.edit', 'name' => 'Edit Notices', 'description' => 'Update existing notices.'],
        ['group' => 'Notice Board', 'module' => 'notice', 'code' => 'notice.delete', 'name' => 'Delete Notices', 'description' => 'Remove published notices.'],
        ['group' => 'Exams', 'module' => 'exams', 'code' => 'exams.view', 'name' => 'View Exams', 'description' => 'View exam schedules and results.'],
        ['group' => 'Exams', 'module' => 'exams', 'code' => 'exams.create', 'name' => 'Create Exams', 'description' => 'Create new exam entries.'],
        ['group' => 'Exams', 'module' => 'exams', 'code' => 'exams.edit', 'name' => 'Edit Exams', 'description' => 'Update exam details.'],
        ['group' => 'Exams', 'module' => 'exams', 'code' => 'exams.delete', 'name' => 'Delete Exams', 'description' => 'Remove exam entries.'],
        ['group' => 'Exam Sessions', 'module' => 'exam-sessions', 'code' => 'exam_sessions.view', 'name' => 'View Exam Sessions', 'description' => 'View online exam sessions and attempts.'],
        ['group' => 'Exam Sessions', 'module' => 'exam-sessions', 'code' => 'exam_sessions.create', 'name' => 'Create Exam Sessions', 'description' => 'Create new online exam sessions.'],
        ['group' => 'Exam Sessions', 'module' => 'exam-sessions', 'code' => 'exam_sessions.edit', 'name' => 'Edit Exam Sessions', 'description' => 'Update exam session details and transitions.'],
        ['group' => 'Exam Sessions', 'module' => 'exam-sessions', 'code' => 'exam_sessions.delete', 'name' => 'Delete Exam Sessions', 'description' => 'Remove exam sessions.'],
        ['group' => 'Question Papers', 'module' => 'question-papers', 'code' => 'question-papers.view', 'name' => 'View Question Papers', 'description' => 'View question papers, previews, and questions.'],
        ['group' => 'Question Papers', 'module' => 'question-papers', 'code' => 'question-papers.create', 'name' => 'Create Question Papers', 'description' => 'Create question papers and questions.'],
        ['group' => 'Question Papers', 'module' => 'question-papers', 'code' => 'question-papers.edit', 'name' => 'Edit Question Papers', 'description' => 'Edit question papers, questions, and reorder questions.'],
        ['group' => 'Question Papers', 'module' => 'question-papers', 'code' => 'question-papers.delete', 'name' => 'Delete Question Papers', 'description' => 'Delete question papers and questions.'],
        ['group' => 'Question Papers', 'module' => 'question-papers', 'code' => 'question-papers.finalize', 'name' => 'Finalize Question Papers', 'description' => 'Finalize question papers.'],
        ['group' => 'Question Papers', 'module' => 'question-papers', 'code' => 'question-papers.export', 'name' => 'Export Question Papers', 'description' => 'Export question papers to PDF.'],
        ['group' => 'Reports', 'module' => 'reports', 'code' => 'reports.view', 'name' => 'View Reports', 'description' => 'Access generated reports.'],
        ['group' => 'Settings', 'module' => 'settings', 'code' => 'settings.view', 'name' => 'View Settings', 'description' => 'View application settings.'],
        ['group' => 'Settings', 'module' => 'settings', 'code' => 'settings.edit', 'name' => 'Edit Settings', 'description' => 'Modify application settings.'],
        ['group' => 'Access Control', 'module' => 'access_control', 'code' => 'access_control.view', 'name' => 'View Access Control', 'description' => 'View role and permission configurations.'],
        ['group' => 'Access Control', 'module' => 'access_control', 'code' => 'access_control.edit', 'name' => 'Edit Access Control', 'description' => 'Modify role and permission assignments.'],
        ['group' => 'Classes', 'module' => 'classes', 'code' => 'classes.view', 'name' => 'View Classes', 'description' => 'View academic classes.'],
        ['group' => 'Classes', 'module' => 'classes', 'code' => 'classes.create', 'name' => 'Create Classes', 'description' => 'Create new academic classes.'],
        ['group' => 'Classes', 'module' => 'classes', 'code' => 'classes.edit', 'name' => 'Edit Classes', 'description' => 'Modify academic classes.'],
        ['group' => 'Classes', 'module' => 'classes', 'code' => 'classes.delete', 'name' => 'Delete Classes', 'description' => 'Remove academic classes.'],
        ['group' => 'Subjects', 'module' => 'subjects', 'code' => 'subjects.view', 'name' => 'View Subjects', 'description' => 'View subjects.'],
        ['group' => 'Subjects', 'module' => 'subjects', 'code' => 'subjects.create', 'name' => 'Create Subjects', 'description' => 'Create new subjects.'],
        ['group' => 'Subjects', 'module' => 'subjects', 'code' => 'subjects.edit', 'name' => 'Edit Subjects', 'description' => 'Modify subjects.'],
        ['group' => 'Subjects', 'module' => 'subjects', 'code' => 'subjects.delete', 'name' => 'Delete Subjects', 'description' => 'Remove subjects.'],
        ['group' => 'Sections', 'module' => 'sections', 'code' => 'sections.view', 'name' => 'View Sections', 'description' => 'View class sections.'],
        ['group' => 'Sections', 'module' => 'sections', 'code' => 'sections.create', 'name' => 'Create Sections', 'description' => 'Create new class sections.'],
        ['group' => 'Sections', 'module' => 'sections', 'code' => 'sections.edit', 'name' => 'Edit Sections', 'description' => 'Modify class sections.'],
        ['group' => 'Sections', 'module' => 'sections', 'code' => 'sections.delete', 'name' => 'Delete Sections', 'description' => 'Remove class sections.'],
        ['group' => 'Academic Years', 'module' => 'academic-years', 'code' => 'academic-years.view', 'name' => 'View Academic Years', 'description' => 'View academic years.'],
        ['group' => 'Academic Years', 'module' => 'academic-years', 'code' => 'academic-years.create', 'name' => 'Create Academic Years', 'description' => 'Create new academic years.'],
        ['group' => 'Academic Years', 'module' => 'academic-years', 'code' => 'academic-years.edit', 'name' => 'Edit Academic Years', 'description' => 'Modify academic years.'],
        ['group' => 'Academic Years', 'module' => 'academic-years', 'code' => 'academic-years.delete', 'name' => 'Delete Academic Years', 'description' => 'Remove academic years.'],
    ];

    private const DEFAULT_MAPPINGS = [
        'ROLE_SUPER_ADMIN' => null,
        'ROLE_INSTITUTE' => [
            'dashboard.view',
            'users.view', 'users.create', 'users.edit', 'users.delete', 'users.status', 'users.reset_password', 'users.manage_permissions',
            'students.view', 'students.create', 'students.edit', 'students.delete', 'students.import', 'students.export',
            'teachers.view', 'teachers.create', 'teachers.edit', 'teachers.delete',
            'teacher_assignments.view', 'teacher_assignments.create', 'teacher_assignments.edit', 'teacher_assignments.delete',
            'student_enrollments.view', 'student_enrollments.create', 'student_enrollments.edit', 'student_enrollments.delete',
            'attendance.view', 'attendance.mark', 'attendance.edit',
            'homework.view', 'homework.create', 'homework.edit', 'homework.delete',
            'notice.view', 'notice.create', 'notice.edit', 'notice.delete',
            'exams.view', 'exams.create', 'exams.edit', 'exams.delete',
            'exam_sessions.view', 'exam_sessions.create', 'exam_sessions.edit', 'exam_sessions.delete',
            'question-papers.view', 'question-papers.create', 'question-papers.edit', 'question-papers.delete', 'question-papers.finalize', 'question-papers.export',
            'reports.view', 'reports.generate',
            'settings.view', 'settings.edit',
            'access_control.view', 'access_control.edit',
            'classes.view', 'classes.create', 'classes.edit', 'classes.delete',
            'subjects.view', 'subjects.create', 'subjects.edit', 'subjects.delete',
            'sections.view', 'sections.create', 'sections.edit', 'sections.delete',
            'academic_years.view', 'academic_years.create', 'academic_years.edit', 'academic_years.delete',
        ],
        'ROLE_TEACHER' => [
            'dashboard.view',
            'students.view',
            'teacher_assignments.view',
            'student_enrollments.view',
            'attendance.view', 'attendance.mark', 'attendance.edit',
            'homework.view', 'homework.create', 'homework.edit', 'homework.delete',
            'notice.view',
            'exams.view', 'exams.create', 'exams.edit',
            'exam_sessions.view', 'exam_sessions.create', 'exam_sessions.edit', 'exam_sessions.delete',
            'question-papers.view', 'question-papers.create', 'question-papers.edit', 'question-papers.delete', 'question-papers.finalize', 'question-papers.export',
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
