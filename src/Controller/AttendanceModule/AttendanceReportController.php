<?php
declare(strict_types=1);

namespace App\Controller\AttendanceModule;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\Enum\UserRole;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\AttendanceModule\AttendanceReportingService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Component\Uid\Uuid;

class AttendanceReportController extends AbstractApiController
{
    public function __construct(
        private AttendanceReportingService $reportingService,
        private Security $security,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/attendance/dashboard', name: 'attendance_dashboard_stats', methods: ['GET'])]
    public function dashboardStats(Request $request): JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $stats = $this->reportingService->getDashboardStats(
                $instituteId,
                (string) $request->query->get('academicYearId'),
                (string) $request->query->get('academicClassId'),
                $request->query->get('sectionId')
            );

            return $this->success('Dashboard stats retrieved successfully.', $stats);
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/attendance/students/{studentId}/dashboard', name: 'attendance_student_dashboard', methods: ['GET'])]
    public function studentDashboard(Request $request, string $studentId): JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $dashboard = $this->reportingService->getStudentDashboard(
                $instituteId,
                $studentId,
                $request->query->get('academicYearId'),
                $request->query->get('startDate'),
                $request->query->get('endDate')
            );

            return $this->success('Student attendance dashboard retrieved successfully.', $dashboard);
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/attendance/students/{studentId}/subjects', name: 'attendance_student_subjects', methods: ['GET'])]
    public function studentSubjects(Request $request, string $studentId): JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $result = $this->reportingService->getStudentSubjectAttendance(
                $instituteId,
                $studentId,
                $request->query->get('academicYearId')
            );

            return $this->success('Student subject attendance retrieved successfully.', $result);
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/attendance/students/{studentId}/alerts', name: 'attendance_student_alerts', methods: ['GET'])]
    public function studentAlerts(Request $request, string $studentId): JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $alerts = $this->reportingService->getStudentAlerts(
                $instituteId,
                $studentId,
                $request->query->get('academicYearId')
            );

            return $this->success('Student attendance alerts retrieved successfully.', $alerts);
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/attendance/class/{classId}/report', name: 'attendance_class_report', methods: ['GET'])]
    public function classReport(Request $request, string $classId): JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $report = $this->reportingService->getClassAttendanceReport(
                $instituteId,
                $classId,
                $request->query->get('sectionId'),
                $request->query->get('academicYearId'),
                $request->query->get('startDate'),
                $request->query->get('endDate'),
                max(1, (int) $request->query->get('page', 1)),
                max(1, min(100, (int) $request->query->get('limit', 10))),
                $request->query->get('sortBy', 'lastName'),
                $request->query->get('sortOrder', 'ASC')
            );

            return $this->success('Class attendance report retrieved successfully.', $report);
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/attendance/today', name: 'attendance_today', methods: ['GET'])]
    public function today(Request $request): JsonResponse
    {
        try {
            $user = $this->security->getUser();
            if ($user instanceof User && in_array(UserRole::Student->value, $user->getRoles(), true)) {
                throw new AuthenticationException('Students are not authorized to access today\'s attendance.');
            }
            $instituteId = $this->getCurrentInstituteId();
$attendance = $this->reportingService->getTodayAttendance(
            $instituteId,
            $request->query->get('academicYearId'),
            (string) $request->query->get('academicClassId'),
            $request->query->get('sectionId')
        );

            return $this->success('Today\'s attendance retrieved successfully.', $attendance);
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
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
}
