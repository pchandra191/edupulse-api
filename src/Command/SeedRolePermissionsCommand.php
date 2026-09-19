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
        ['group' => 'Teacher Assignments', 'module' => 'teacher-assignments', 'code' => 'teacher_assignments.view', 'name' => 'View Teacher Assignments', 'description' => 'View teacher class assignments.'],
        ['group' => 'Teacher Assignments', 'module' => 'teacher-assignments', 'code' => 'teacher_assignments.create', 'name' => 'Create Teacher Assignments', 'description' => 'Assign teachers to classes.'],
        ['group' => 'Teacher Assignments', 'module' => 'teacher-assignments', 'code' => 'teacher_assignments.edit', 'name' => 'Edit Teacher Assignments', 'description' => 'Modify teacher class assignments.'],
        ['group' => 'Teacher Assignments', 'module' => 'teacher-assignments', 'code' => 'teacher_assignments.delete', 'name' => 'Delete Teacher Assignments', 'description' => 'Remove teacher class assignments.'],
        ['group' => 'Student Enrollments', 'module' => 'student-enrollments', 'code' => 'student_enrollments.view', 'name' => 'View Student Enrollments', 'description' => 'View student enrollment records.'],
        ['group' => 'Student Enrollments', 'module' => 'student-enrollments', 'code' => 'student_enrollments.create', 'name' => 'Create Student Enrollments', 'description' => 'Enroll students in classes.'],
        ['group' => 'Student Enrollments', 'module' => 'student-enrollments', 'code' => 'student_enrollments.edit', 'name' => 'Edit Student Enrollments', 'description' => 'Modify student enrollment records.'],
        ['group' => 'Student Enrollments', 'module' => 'student-enrollments', 'code' => 'student_enrollments.delete', 'name' => 'Delete Student Enrollments', 'description' => 'Remove student enrollment records.'],
         ['group' => 'Attendance', 'module' => 'attendance', 'code' => 'attendance.view', 'name' => 'View Attendance', 'description' => 'View attendance records.'],
        ['group' => 'Attendance', 'module' => 'attendance', 'code' => 'attendance.mark', 'name' => 'Mark Attendance', 'description' => 'Mark student attendance.'],
        ['group' => 'Attendance', 'module' => 'attendance', 'code' => 'attendance.edit', 'name' => 'Edit Attendance', 'description' => 'Modify attendance records.'],
        ['group' => 'Attendance', 'module' => 'attendance', 'code' => 'attendance.dashboard', 'name' => 'View Attendance Dashboard', 'description' => 'Access attendance dashboard and statistics.'],
        ['group' => 'Attendance', 'module' => 'attendance', 'code' => 'attendance.report', 'name' => 'View Attendance Reports', 'description' => 'Access attendance reports and analytics.'],
        ['group' => 'Attendance', 'module' => 'attendance', 'code' => 'attendance.correction.request', 'name' => 'Request Attendance Correction', 'description' => 'Request corrections to attendance records.'],
        ['group' => 'Attendance', 'module' => 'attendance', 'code' => 'attendance.correction.approve', 'name' => 'Approve Attendance Corrections', 'description' => 'Approve or reject attendance correction requests.'],
        ['group' => 'Attendance', 'module' => 'attendance', 'code' => 'attendance.policy.view', 'name' => 'View Attendance Policies', 'description' => 'View attendance policy configurations.'],
        ['group' => 'Attendance', 'module' => 'attendance', 'code' => 'attendance.policy.manage', 'name' => 'Manage Attendance Policies', 'description' => 'Create, edit, and delete attendance policies.'],
        ['group' => 'Holidays', 'module' => 'holidays', 'code' => 'holidays.view', 'name' => 'View Holidays', 'description' => 'View holiday calendar and list.'],
        ['group' => 'Holidays', 'module' => 'holidays', 'code' => 'holidays.manage', 'name' => 'Manage Holidays', 'description' => 'Create, edit, and delete holidays.'],
        ['group' => 'Leave Types', 'module' => 'leave-types', 'code' => 'leave_types.view', 'name' => 'View Leave Types', 'description' => 'View leave type configurations.'],
        ['group' => 'Leave Types', 'module' => 'leave-types', 'code' => 'leave_types.manage', 'name' => 'Manage Leave Types', 'description' => 'Create, edit, and delete leave types.'],
        ['group' => 'Leave Requests', 'module' => 'leave-requests', 'code' => 'leave_requests.view', 'name' => 'View Leave Requests', 'description' => 'View leave requests.'],
        ['group' => 'Leave Requests', 'module' => 'leave-requests', 'code' => 'leave_requests.apply', 'name' => 'Apply for Leave', 'description' => 'Submit new leave requests.'],
        ['group' => 'Leave Requests', 'module' => 'leave-requests', 'code' => 'leave_requests.approve', 'name' => 'Approve Leave Requests', 'description' => 'Approve leave requests.'],
        ['group' => 'Leave Requests', 'module' => 'leave-requests', 'code' => 'leave_requests.reject', 'name' => 'Reject Leave Requests', 'description' => 'Reject leave requests.'],
        ['group' => 'Leave Requests', 'module' => 'leave-requests', 'code' => 'leave_requests.cancel', 'name' => 'Cancel Leave Requests', 'description' => 'Cancel submitted leave requests.'],
        ['group' => 'Leave Balances', 'module' => 'leave-balances', 'code' => 'leave_balances.view', 'name' => 'View Leave Balances', 'description' => 'View student leave balances.'],
        ['group' => 'Leave Balances', 'module' => 'leave-balances', 'code' => 'leave_balances.manage', 'name' => 'Manage Leave Balances', 'description' => 'Initialize and manage leave balances.'],
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
        ['group' => 'Exam Attempts', 'module' => 'exam-attempts', 'code' => 'exam_attempts.view', 'name' => 'View Exam Attempts', 'description' => 'View exam attempts and results.'],
        ['group' => 'Exam Attempts', 'module' => 'exam-attempts', 'code' => 'exam_attempts.start', 'name' => 'Start Exam Attempt', 'description' => 'Start a new exam attempt.'],
        ['group' => 'Exam Attempts', 'module' => 'exam-attempts', 'code' => 'exam_attempts.submit', 'name' => 'Submit Exam Attempt', 'description' => 'Submit an exam attempt.'],
        ['group' => 'Exam Answers', 'module' => 'exam-answers', 'code' => 'exam_answers.view', 'name' => 'View Exam Answers', 'description' => 'View student exam answers.'],
        ['group' => 'Exam Answers', 'module' => 'exam-answers', 'code' => 'exam_answers.create', 'name' => 'Create Exam Answers', 'description' => 'Save exam answers.'],
        ['group' => 'Exam Submission Documents', 'module' => 'exam-submission-documents', 'code' => 'exam_submission_documents.view', 'name' => 'View Exam Submission Documents', 'description' => 'View uploaded exam answer documents.'],
        ['group' => 'Exam Submission Documents', 'module' => 'exam-submission-documents', 'code' => 'exam_submission_documents.create', 'name' => 'Create Exam Submission Documents', 'description' => 'Upload exam answer documents.'],
        ['group' => 'Exam Submission Documents', 'module' => 'exam-submission-documents', 'code' => 'exam_submission_documents.delete', 'name' => 'Delete Exam Submission Documents', 'description' => 'Delete uploaded exam answer documents.'],
        ['group' => 'Question Papers', 'module' => 'question-papers', 'code' => 'question_papers.view', 'name' => 'View Question Papers', 'description' => 'View question papers, previews, and questions.'],
        ['group' => 'Question Papers', 'module' => 'question-papers', 'code' => 'question_papers.create', 'name' => 'Create Question Papers', 'description' => 'Create question papers and questions.'],
        ['group' => 'Question Papers', 'module' => 'question-papers', 'code' => 'question_papers.edit', 'name' => 'Edit Question Papers', 'description' => 'Edit question papers, questions, and reorder questions.'],
        ['group' => 'Question Papers', 'module' => 'question-papers', 'code' => 'question_papers.delete', 'name' => 'Delete Question Papers', 'description' => 'Delete question papers and questions.'],
        ['group' => 'Question Papers', 'module' => 'question-papers', 'code' => 'question_papers.finalize', 'name' => 'Finalize Question Papers', 'description' => 'Finalize question papers.'],
        ['group' => 'Question Papers', 'module' => 'question-papers', 'code' => 'question_papers.export', 'name' => 'Export Question Papers', 'description' => 'Export question papers to PDF.'],
        ['group' => 'Reports', 'module' => 'reports', 'code' => 'reports.view', 'name' => 'View Reports', 'description' => 'Access generated reports.'],
        ['group' => 'Reports', 'module' => 'reports', 'code' => 'reports.generate', 'name' => 'Generate Reports', 'description' => 'Generate and export new reports.'],
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
        ['group' => 'Academic Years', 'module' => 'academic-years', 'code' => 'academic_years.view', 'name' => 'View Academic Years', 'description' => 'View academic years.'],
        ['group' => 'Academic Years', 'module' => 'academic-years', 'code' => 'academic_years.create', 'name' => 'Create Academic Years', 'description' => 'Create new academic years.'],
        ['group' => 'Academic Years', 'module' => 'academic-years', 'code' => 'academic_years.edit', 'name' => 'Edit Academic Years', 'description' => 'Modify academic years.'],
        ['group' => 'Academic Years', 'module' => 'academic-years', 'code' => 'academic_years.delete', 'name' => 'Delete Academic Years', 'description' => 'Remove academic years.'],
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
            'attendance.view', 'attendance.mark', 'attendance.edit', 'attendance.dashboard', 'attendance.report',
            'attendance.correction.request', 'attendance.correction.approve',
            'attendance.policy.view', 'attendance.policy.manage',
            'holidays.view', 'holidays.manage',
            'leave_types.view', 'leave_types.manage',
            'leave_requests.view', 'leave_requests.approve', 'leave_requests.reject',
            'leave_balances.view', 'leave_balances.manage',
            'homework.view', 'homework.create', 'homework.edit', 'homework.delete',
            'notice.view', 'notice.create', 'notice.edit', 'notice.delete',
            'exams.view', 'exams.create', 'exams.edit', 'exams.delete',
            'exam_sessions.view', 'exam_sessions.create', 'exam_sessions.edit', 'exam_sessions.delete',
            'exam_attempts.view', 'exam_attempts.start',
            'exam_answers.view', 'exam_answers.create',
            'exam_submission_documents.view', 'exam_submission_documents.create', 'exam_submission_documents.delete',
            'question_papers.view', 'question_papers.create', 'question_papers.edit', 'question_papers.delete', 'question_papers.finalize', 'question_papers.export',
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
            'attendance.view', 'attendance.mark', 'attendance.edit', 'attendance.dashboard',
            'attendance.correction.request', 'attendance.correction.approve',
            'attendance.policy.view',
            'holidays.view',
            'leave_types.view',
            'leave_requests.view', 'leave_requests.approve', 'leave_requests.reject',
            'leave_balances.view',
            'homework.view', 'homework.create', 'homework.edit', 'homework.delete',
            'notice.view',
            'exams.view', 'exams.create', 'exams.edit',
            'exam_sessions.view', 'exam_sessions.create', 'exam_sessions.edit', 'exam_sessions.delete',
            'exam_attempts.view',
            'exam_answers.view', 'exam_answers.create',
            'exam_submission_documents.view', 'exam_submission_documents.create', 'exam_submission_documents.delete',
            'question_papers.view', 'question_papers.create', 'question_papers.edit', 'question_papers.delete', 'question_papers.finalize', 'question_papers.export',
            'reports.view',
        ],
        'ROLE_STUDENT' => [
            'dashboard.view',
            'notice.view',
            'homework.view',
            'attendance.view',
            'attendance.dashboard',
            'attendance.correction.request',
            'leave_types.view',
            'leave_requests.view', 'leave_requests.apply', 'leave_requests.cancel',
            'leave_balances.view',
            'exams.view',
            'exam_answers.view', 'exam_answers.create',
            'exam_submission_documents.view', 'exam_submission_documents.create',
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
