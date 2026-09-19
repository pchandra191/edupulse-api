<?php
declare(strict_types=1);

namespace App\Controller\AttendanceModule;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\Common\Api\Response\PaginationResponse;
use App\DTO\AttendanceModule\AttendanceFilterRequest;
use App\DTO\AttendanceModule\AttendanceRecordBulkRequestDTO;
use App\DTO\AttendanceModule\AttendanceRecordUpdateRequestDTO;
use App\DTO\AttendanceModule\StudentAttendanceRecordDTO;
use App\DTO\AttendanceModule\StudentCheckInRequestDTO;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\AuthenticationException;
use App\Service\AttendanceModule\AttendanceService;
use App\Service\AttendanceModule\AttendanceDateTimeProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AttendanceController extends AbstractApiController
{
    public function __construct(
        private AttendanceService $attendanceService,
        private ValidatorInterface $validator,
        private Security $security,
        private AttendanceDateTimeProvider $dateTimeProvider,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/attendance/roster', name: 'attendance_roster', methods: ['GET'])]
    public function roster(Request $request): JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();

            $roster = $this->attendanceService->getAttendanceRoster(
                $instituteId,
                (string) $request->query->get('academicYearId'),
                (string) $request->query->get('academicClassId'),
                (string) $request->query->get('sectionId'),
                (string) $request->query->get('date')
            );

            return $this->success('Attendance roster retrieved successfully.', $roster);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/attendance/bulk', name: 'attendance_bulk_mark', methods: ['POST'])]
    public function bulkMark(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user instanceof User && in_array(UserRole::Student->value, $user->getRoles(), true)) {
            return $this->accessDenied('Students are not authorized to mark attendance.');
        }
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $records = [];
        foreach ($data['records'] ?? [] as $record) {
            $records[] = new StudentAttendanceRecordDTO(
                studentId: (string) ($record['studentId'] ?? ''),
                status: (string) ($record['status'] ?? ''),
                remarks: $record['remarks'] ?? null,
            );
        }

        $bulkRequest = new AttendanceRecordBulkRequestDTO(
            academicYearId: (string) ($data['academicYearId'] ?? ''),
            classId: (string) ($data['classId'] ?? ''),
            sectionId: (string) ($data['sectionId'] ?? ''),
            attendanceDate: (string) ($data['attendanceDate'] ?? ''),
            records: $records,
        );

        $errors = $this->validator->validate($bulkRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $instituteId = $this->getCurrentInstituteId();
            $results = $this->attendanceService->bulkMarkAttendance($instituteId, $bulkRequest);

            return $this->success('Attendance marked successfully.', $results);
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_STUDENT')")]
    #[Route('/attendance/check-in', name: 'attendance_check_in', methods: ['POST'])]
    public function checkIn(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $latitude = $data['latitude'] ?? null;
        $longitude = $data['longitude'] ?? null;
        $accuracy = $data['accuracyMeters'] ?? null;

        if ($latitude === null || $longitude === null || $accuracy === null) {
            return $this->unprocessableEntity('Latitude, longitude, and accuracyMeters are required for check-in.');
        }

        if (!is_numeric($latitude) || !is_numeric($longitude) || !is_numeric($accuracy)) {
            return $this->unprocessableEntity('Latitude, longitude, and accuracyMeters must be numeric values.');
        }

        $location = new StudentCheckInRequestDTO(
            latitude: (float) $latitude,
            longitude: (float) $longitude,
            accuracyMeters: (float) $accuracy,
        );

        $errors = $this->validator->validate($location);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Location validation failed.', $formattedErrors);
        }

        try {
            $instituteId = $this->getCurrentInstituteId();
            $result = $this->attendanceService->studentCheckIn($instituteId, $location);

            return $this->success('Attendance marked successfully.', $result);
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/attendance', name: 'attendance_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $filter = new AttendanceFilterRequest(
            studentId: $request->query->get('studentId'),
            academicYearId: $request->query->get('academicYearId'),
            classId: $request->query->get('classId') ?? $request->query->get('academicClassId'),
            sectionId: $request->query->get('sectionId'),
            startDate: $request->query->get('startDate') ?? $request->query->get('dateFrom') ?? $request->query->get('date'),
            endDate: $request->query->get('endDate') ?? $request->query->get('dateTo') ?? $request->query->get('date'),
            status: $request->query->get('status'),
            page: max(1, (int) $request->query->get('page', 1)),
            limit: max(1, min(100, (int) ($request->query->get('limit') ?? $request->query->get('pageSize', 10)))),
            sortBy: $request->query->get('sortBy', 'attendanceDate'),
            sortOrder: $request->query->get('sortOrder', 'DESC'),
        );

        try {
            $instituteId = $this->getCurrentInstituteId();
            $result = $this->attendanceService->getStudentAttendance($instituteId, $filter);

            return $this->paginated('Attendance records retrieved successfully.', $result->items, new PaginationResponse(
                page: $result->pagination['page'],
                limit: $result->pagination['limit'],
                totalItems: $result->pagination['totalItems'],
                totalPages: $result->pagination['totalPages'],
            ));
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/attendance/{id}', name: 'attendance_get', methods: ['GET'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}'])]
    public function get(string $id): JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $record = $this->attendanceService->getAttendanceRecord($instituteId, $id);

            return $this->success('Attendance record retrieved successfully.', $record);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/attendance/{id}', name: 'attendance_update', methods: ['PUT'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $updateRequest = new AttendanceRecordUpdateRequestDTO(
            status: (string) ($data['status'] ?? ''),
            remarks: $data['remarks'] ?? null,
        );

        $errors = $this->validator->validate($updateRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $instituteId = $this->getCurrentInstituteId();
            $record = $this->attendanceService->updateAttendanceRecord($instituteId, $id, $updateRequest);

            return $this->updated('Attendance record updated successfully.', $record);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/attendance/students/{studentId}', name: 'attendance_student_list', methods: ['GET'])]
    public function studentAttendance(Request $request, string $studentId): JsonResponse
    {
        $filter = new AttendanceFilterRequest(
            studentId: $studentId,
            academicYearId: $request->query->get('academicYearId'),
            classId: $request->query->get('classId') ?? $request->query->get('academicClassId'),
            sectionId: $request->query->get('sectionId'),
            startDate: $request->query->get('startDate') ?? $request->query->get('dateFrom') ?? $request->query->get('date'),
            endDate: $request->query->get('endDate') ?? $request->query->get('dateTo') ?? $request->query->get('date'),
            status: $request->query->get('status'),
            page: max(1, (int) $request->query->get('page', 1)),
            limit: max(1, min(100, (int) ($request->query->get('limit') ?? $request->query->get('pageSize', 10)))),
            sortBy: $request->query->get('sortBy', 'attendanceDate'),
            sortOrder: $request->query->get('sortOrder', 'DESC'),
        );

        try {
            $instituteId = $this->getCurrentInstituteId();
            $result = $this->attendanceService->getStudentAttendance($instituteId, $filter);

            return $this->paginated('Student attendance retrieved successfully.', $result->items, new PaginationResponse(
                page: $result->pagination['page'],
                limit: $result->pagination['limit'],
                totalItems: $result->pagination['totalItems'],
                totalPages: $result->pagination['totalPages'],
            ));
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/attendance/students/{studentId}/summary', name: 'attendance_student_summary', methods: ['GET'])]
    public function studentSummary(Request $request, string $studentId): JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $summary = $this->attendanceService->getStudentAttendanceSummary(
                $instituteId,
                $studentId,
                $request->query->get('academicYearId'),
                $request->query->get('startDate') ?? $request->query->get('dateFrom') ?? $request->query->get('date'),
                $request->query->get('endDate') ?? $request->query->get('dateTo') ?? $request->query->get('date')
            );

            return $this->success('Student attendance summary retrieved successfully.', $summary);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/attendance/students/{studentId}/calendar', name: 'attendance_student_calendar', methods: ['GET'])]
    public function studentCalendar(Request $request, string $studentId): JsonResponse
    {
        $instituteId = $this->getCurrentInstituteId();
        $businessDate = $this->dateTimeProvider->currentBusinessDate($instituteId);
        $year = (int) ($request->query->get('year', $businessDate->format('Y')));
        $month = (int) ($request->query->get('month', $businessDate->format('m')));

        try {
            $instituteId = $this->getCurrentInstituteId();
            $calendar = $this->attendanceService->getStudentAttendanceCalendar(
                $instituteId,
                $studentId,
                $year,
                $month
            );

            return $this->success('Student attendance calendar retrieved successfully.', $calendar);
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/attendance/students/{studentId}/history', name: 'attendance_student_history', methods: ['GET'])]
    public function studentHistory(Request $request, string $studentId): JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $result = $this->attendanceService->getStudentAttendanceHistory(
                $instituteId,
                $studentId,
                $request->query->get('academicYearId'),
                $request->query->get('startDate') ?? $request->query->get('dateFrom') ?? $request->query->get('date'),
                $request->query->get('endDate') ?? $request->query->get('dateTo') ?? $request->query->get('date'),
                max(1, (int) $request->query->get('page', 1)),
                max(1, min(100, (int) ($request->query->get('limit') ?? $request->query->get('pageSize', 10)))),
                $request->query->get('sortBy', 'attendanceDate'),
                $request->query->get('sortOrder', 'DESC')
            );

            return $this->paginated('Student attendance history retrieved successfully.', $result->items, new PaginationResponse(
                page: $result->pagination['page'],
                limit: $result->pagination['limit'],
                totalItems: $result->pagination['totalItems'],
                totalPages: $result->pagination['totalPages'],
            ));
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
