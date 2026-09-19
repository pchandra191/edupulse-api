<?php
declare(strict_types=1);

namespace App\Controller\AttendanceModule;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\Common\Api\Response\PaginationResponse;
use App\DTO\AttendanceModule\AttendancePolicyCreateRequestDTO;
use App\DTO\AttendanceModule\AttendancePolicyUpdateRequestDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\AttendanceModule\AttendancePolicyService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AttendancePolicyController extends AbstractApiController
{
    public function __construct(
        private AttendancePolicyService $policyService,
        private ValidatorInterface $validator,
        private Security $security,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/attendance/policies', name: 'attendance_policy_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $instituteId = $this->getCurrentInstituteId();
        $result = $this->policyService->getPolicies(
            $instituteId,
            $request->query->get('academicYearId'),
            $request->query->get('status'),
            max(1, (int) $request->query->get('page', 1)),
            max(1, min(100, (int) $request->query->get('limit', 10))),
            $request->query->get('sortBy', 'createdAt'),
            $request->query->get('sortOrder', 'DESC')
        );

        return $this->paginated('Attendance policies retrieved successfully.', $result->items, new PaginationResponse(
            page: $result->pagination['page'],
            limit: $result->pagination['limit'],
            totalItems: $result->pagination['totalItems'],
            totalPages: $result->pagination['totalPages'],
        ));
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/attendance/policies', name: 'attendance_policy_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $createRequest = new AttendancePolicyCreateRequestDTO(
            minimumAttendancePercentage: isset($data['minimumAttendancePercentage']) ? (int) $data['minimumAttendancePercentage'] : 75,
            lateThresholdCount: isset($data['lateThresholdCount']) ? (int) $data['lateThresholdCount'] : null,
            halfDayBehavior: $data['halfDayBehavior'] ?? null,
            approvedLeaveCounts: (bool) ($data['approvedLeaveCounts'] ?? false),
            workingDays: $data['workingDays'] ?? null,
            academicYearId: $data['academicYearId'] ?? null,
            isActive: (bool) ($data['isActive'] ?? true),
            description: $data['description'] ?? null,
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
            $result = $this->policyService->createPolicy($instituteId, $createRequest);

            return $this->created('Attendance policy created successfully.', $result);
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/attendance/policies/{id}', name: 'attendance_policy_get', methods: ['GET'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}'])]
    public function get(string $id): JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $result = $this->policyService->getPolicy($instituteId, $id);

            return $this->success('Attendance policy retrieved successfully.', $result);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/attendance/policies/{id}', name: 'attendance_policy_update', methods: ['PUT'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $updateRequest = new AttendancePolicyUpdateRequestDTO(
            minimumAttendancePercentage: isset($data['minimumAttendancePercentage']) ? (int) $data['minimumAttendancePercentage'] : null,
            lateThresholdCount: isset($data['lateThresholdCount']) ? (int) $data['lateThresholdCount'] : null,
            halfDayBehavior: $data['halfDayBehavior'] ?? null,
            approvedLeaveCounts: isset($data['approvedLeaveCounts']) ? (bool) $data['approvedLeaveCounts'] : null,
            workingDays: $data['workingDays'] ?? null,
            academicYearId: $data['academicYearId'] ?? null,
            isActive: isset($data['isActive']) ? (bool) $data['isActive'] : null,
            description: $data['description'] ?? null,
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
            $result = $this->policyService->updatePolicy($instituteId, $id, $updateRequest);

            return $this->updated('Attendance policy updated successfully.', $result);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/attendance/policies/{id}', name: 'attendance_policy_delete', methods: ['DELETE'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}'])]
    public function delete(string $id): JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $this->policyService->deletePolicy($instituteId, $id);

            return $this->deleted('Attendance policy deleted successfully.');
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/attendance/policies/active', name: 'attendance_policy_active', methods: ['GET'])]
    public function activePolicy(Request $request): JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $result = $this->policyService->getPolicyForInstitute(
                $instituteId,
                $request->query->get('academicYearId')
            );

            if ($result === null) {
                return $this->success('No active attendance policy found.', null);
            }

            return $this->success('Attendance policy retrieved successfully.', $result);
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
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
