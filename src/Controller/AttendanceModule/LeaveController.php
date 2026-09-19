<?php
declare(strict_types=1);

namespace App\Controller\AttendanceModule;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\Common\Api\Response\PaginationResponse;
use App\DTO\AttendanceModule\LeaveRequestCreateRequestDTO;
use App\DTO\AttendanceModule\LeaveRequestApproveRequestDTO;
use App\DTO\AttendanceModule\LeaveRequestFilterRequest;
use App\DTO\AttendanceModule\LeaveRequestRejectRequestDTO;
use App\DTO\AttendanceModule\LeaveTypeCreateRequestDTO;
use App\DTO\AttendanceModule\LeaveTypeFilterRequest;
use App\DTO\AttendanceModule\LeaveTypeUpdateRequestDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\AttendanceModule\LeaveService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class LeaveController extends AbstractApiController
{
    public function __construct(
        private LeaveService $leaveService,
        private ValidatorInterface $validator,
        private Security $security,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/leaves/types', name: 'leave_type_list', methods: ['GET'])]
    public function typeList(Request $request): JsonResponse
    {
        $filter = new LeaveTypeFilterRequest(
            search: $request->query->get('search'),
            enabled: $request->query->get('enabled'),
            page: max(1, (int) $request->query->get('page', 1)),
            limit: max(1, min(100, (int) $request->query->get('limit', 10))),
            sortBy: $request->query->get('sortBy', 'createdAt'),
            sortOrder: $request->query->get('sortOrder', 'DESC'),
        );

        $instituteId = $this->getCurrentInstituteId();
        $result = $this->leaveService->getLeaveTypes($instituteId, $filter);

        return $this->paginated('Leave types retrieved successfully.', $result->items, new PaginationResponse(
            page: $result->pagination['page'],
            limit: $result->pagination['limit'],
            totalItems: $result->pagination['totalItems'],
            totalPages: $result->pagination['totalPages'],
        ));
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/leaves/types', name: 'leave_type_create', methods: ['POST'])]
    public function typeCreate(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $createRequest = new LeaveTypeCreateRequestDTO(
            name: (string) ($data['name'] ?? ''),
            code: (string) ($data['code'] ?? ''),
            description: $data['description'] ?? null,
            enabled: (bool) ($data['enabled'] ?? true),
            requiresApproval: (bool) ($data['requiresApproval'] ?? true),
            maximumDays: isset($data['maximumDays']) ? (int) $data['maximumDays'] : null,
            carryForward: (bool) ($data['carryForward'] ?? false),
            instituteId: $data['instituteId'] ?? null,
        );

        $errors = $this->validator->validate($createRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $instituteId = $this->getCurrentInstituteId();
            $result = $this->leaveService->createLeaveType($instituteId, $createRequest);

            return $this->created('Leave type created successfully.', $result);
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/leaves/types/{id}', name: 'leave_type_get', methods: ['GET'])]
    public function typeGet(string $id): JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $result = $this->leaveService->getLeaveType($instituteId, $id);

            return $this->success('Leave type retrieved successfully.', $result);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/leaves/types/{id}', name: 'leave_type_update', methods: ['PUT'])]
    public function typeUpdate(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $updateRequest = new LeaveTypeUpdateRequestDTO(
            name: $data['name'] ?? null,
            code: $data['code'] ?? null,
            description: $data['description'] ?? null,
            enabled: isset($data['enabled']) ? (bool) $data['enabled'] : null,
            requiresApproval: isset($data['requiresApproval']) ? (bool) $data['requiresApproval'] : null,
            maximumDays: isset($data['maximumDays']) ? (int) $data['maximumDays'] : null,
            carryForward: isset($data['carryForward']) ? (bool) $data['carryForward'] : null,
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
            $result = $this->leaveService->updateLeaveType($instituteId, $id, $updateRequest);

            return $this->updated('Leave type updated successfully.', $result);
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/leaves/types/{id}', name: 'leave_type_delete', methods: ['DELETE'])]
    public function typeDelete(string $id): JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $this->leaveService->deleteLeaveType($instituteId, $id);

            return $this->deleted('Leave type deleted successfully.');
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_TEACHER') or is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_STUDENT')")]
    #[Route('/leaves/requests', name: 'leave_request_list', methods: ['GET'])]
    public function requestList(Request $request): JsonResponse
    {
        $filter = new LeaveRequestFilterRequest(
            studentId: $request->query->get('studentId'),
            leaveTypeId: $request->query->get('leaveTypeId'),
            status: $request->query->get('status'),
            startDate: $request->query->get('startDate'),
            endDate: $request->query->get('endDate'),
            page: max(1, (int) $request->query->get('page', 1)),
            limit: max(1, min(100, (int) $request->query->get('limit', 10))),
            sortBy: $request->query->get('sortBy', 'createdAt'),
            sortOrder: $request->query->get('sortOrder', 'DESC'),
        );

        $userId = $this->getCurrentUserIdString();
        $instituteId = $this->getCurrentInstituteId();
        $result = $this->leaveService->getLeaveRequests($instituteId, $userId, $filter);

        return $this->paginated('Leave requests retrieved successfully.', $result->items, new PaginationResponse(
            page: $result->pagination['page'],
            limit: $result->pagination['limit'],
            totalItems: $result->pagination['totalItems'],
            totalPages: $result->pagination['totalPages'],
        ));
    }

    #[SecurityAttribute("is_granted('ROLE_TEACHER') or is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_STUDENT')")]
    #[Route('/leaves/requests/{id}', name: 'leave_request_get', methods: ['GET'])]
    public function requestGet(string $id): JsonResponse
    {
        try {
            $userId = $this->getCurrentUserIdString();
            $instituteId = $this->getCurrentInstituteId();
            $result = $this->leaveService->getLeaveRequest($instituteId, $userId, $id);

            return $this->success('Leave request retrieved successfully.', $result);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_STUDENT')")]
    #[Route('/leaves/requests', name: 'leave_request_apply', methods: ['POST'])]
    public function requestApply(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $createRequest = new LeaveRequestCreateRequestDTO(
            leaveTypeId: (string) ($data['leaveTypeId'] ?? ''),
            startDate: (string) ($data['startDate'] ?? ''),
            endDate: (string) ($data['endDate'] ?? ''),
            reason: (string) ($data['reason'] ?? ''),
            attachment: $data['attachment'] ?? null,
        );

        $errors = $this->validator->validate($createRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $userId = $this->getCurrentUserIdString();
            $instituteId = $this->getCurrentInstituteId();
            $result = $this->leaveService->applyLeave($instituteId, $userId, $createRequest);

            return $this->created('Leave request submitted successfully.', $result);
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_TEACHER') or is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/leaves/requests/{id}/approve', name: 'leave_request_approve', methods: ['POST'])]
    public function requestApprove(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $approveRequest = new LeaveRequestApproveRequestDTO(
            remarks: is_array($data) ? ($data['remarks'] ?? null) : null,
        );

        try {
            $userId = $this->getCurrentUserIdString();
            $instituteId = $this->getCurrentInstituteId();
            $this->leaveService->approveLeaveRequest($instituteId, $userId, $id, $approveRequest);

            return $this->success('Leave request approved successfully.');
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_TEACHER') or is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/leaves/requests/{id}/reject', name: 'leave_request_reject', methods: ['POST'])]
    public function requestReject(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $rejectRequest = new LeaveRequestRejectRequestDTO(
            remarks: (string) ($data['remarks'] ?? ''),
        );

        $errors = $this->validator->validate($rejectRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $userId = $this->getCurrentUserIdString();
            $instituteId = $this->getCurrentInstituteId();
            $this->leaveService->rejectLeaveRequest($instituteId, $userId, $id, $rejectRequest);

            return $this->success('Leave request rejected successfully.');
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_TEACHER') or is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_STUDENT')")]
    #[Route('/leaves/requests/{id}/cancel', name: 'leave_request_cancel', methods: 'POST')]
    public function requestCancel(string $id): JsonResponse
    {
        try {
            $userId = $this->getCurrentUserIdString();
            $instituteId = $this->getCurrentInstituteId();
            $this->leaveService->cancelLeaveRequest($instituteId, $userId, $id);

            return $this->success('Leave request cancelled successfully.');
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_TEACHER') or is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_STUDENT')")]
    #[Route('/leaves/balances', name: 'leave_balance_list', methods: ['GET'])]
    public function balanceList(Request $request): JsonResponse
    {
        $userId = $this->getCurrentUserIdString();
        $instituteId = $this->getCurrentInstituteId();
        $result = $this->leaveService->getLeaveBalances(
            $instituteId,
            $userId,
            $request->query->get('studentId'),
            $request->query->get('academicYearId')
        );

        return $this->success('Leave balances retrieved successfully.', $result);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_STUDENT')")]
    #[Route('/leaves/balances/initialize', name: 'leave_balance_initialize', methods: ['POST'])]
    public function balanceInitialize(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        try {
            $instituteId = $this->getCurrentInstituteId();
            $this->leaveService->initializeLeaveBalances(
                $instituteId,
                (string) ($data['studentId'] ?? ''),
                (string) ($data['academicYearId'] ?? '')
            );

            return $this->success('Leave balances initialized successfully.');
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
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

    private function getCurrentUserIdString(): string
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return (string) $user->getId();
    }
}
