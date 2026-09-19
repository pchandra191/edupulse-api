<?php
declare(strict_types=1);

namespace App\Controller\AttendanceModule;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\Common\Api\Response\PaginationResponse;
use App\DTO\AttendanceModule\AttendanceCorrectionApproveRequestDTO;
use App\DTO\AttendanceModule\AttendanceCorrectionCreateRequestDTO;
use App\DTO\AttendanceModule\AttendanceCorrectionFilterRequest;
use App\DTO\AttendanceModule\AttendanceCorrectionRejectRequestDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\AttendanceModule\AttendanceCorrectionService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AttendanceCorrectionController extends AbstractApiController
{
    public function __construct(
        private AttendanceCorrectionService $correctionService,
        private ValidatorInterface $validator,
        private Security $security,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/attendance/corrections', name: 'attendance_correction_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $filter = new AttendanceCorrectionFilterRequest(
            studentId: $request->query->get('studentId'),
            attendanceRecordId: $request->query->get('attendanceRecordId'),
            status: $request->query->get('status'),
            page: max(1, (int) $request->query->get('page', 1)),
            limit: max(1, min(100, (int) $request->query->get('limit', 10))),
            sortBy: $request->query->get('sortBy', 'createdAt'),
            sortOrder: $request->query->get('sortOrder', 'DESC'),
        );

        try {
            $instituteId = $this->getCurrentInstituteId();
            $result = $this->correctionService->getCorrections($instituteId, $filter);

            return $this->paginated('Attendance corrections retrieved successfully.', $result->items, new PaginationResponse(
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
    #[Route('/attendance/corrections/{id}', name: 'attendance_correction_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $result = $this->correctionService->getCorrection($instituteId, $id);

            return $this->success('Attendance correction retrieved successfully.', $result);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/attendance/corrections', name: 'attendance_correction_request', methods: ['POST'])]
    public function request(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $createRequest = new AttendanceCorrectionCreateRequestDTO(
            attendanceRecordId: (string) ($data['attendanceRecordId'] ?? ''),
            correctedStatus: (string) ($data['correctedStatus'] ?? ''),
            reason: (string) ($data['reason'] ?? ''),
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
            $user = $this->security->getUser();
            if (!$user instanceof User) {
                throw new AuthenticationException('Unauthenticated.');
            }

            $instituteId = $this->getCurrentInstituteId();
            $result = $this->correctionService->requestCorrection($instituteId, (string) $user->getId(), $createRequest);

            return $this->created('Attendance correction request submitted successfully.', $result);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/attendance/corrections/{id}/approve', name: 'attendance_correction_approve', methods: ['POST'])]
    public function approve(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $approveRequest = new AttendanceCorrectionApproveRequestDTO(
            remarks: is_array($data) ? ($data['remarks'] ?? null) : null,
        );

        try {
            $user = $this->security->getUser();
            if (!$user instanceof User) {
                throw new AuthenticationException('Unauthenticated.');
            }

            $instituteId = $this->getCurrentInstituteId();
            $this->correctionService->approveCorrection($instituteId, (string) $user->getId(), $id, $approveRequest);

            return $this->success('Attendance correction approved successfully.');
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/attendance/corrections/{id}/reject', name: 'attendance_correction_reject', methods: ['POST'])]
    public function reject(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $rejectRequest = new AttendanceCorrectionRejectRequestDTO(
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
            $user = $this->security->getUser();
            if (!$user instanceof User) {
                throw new AuthenticationException('Unauthenticated.');
            }

            $instituteId = $this->getCurrentInstituteId();
            $this->correctionService->rejectCorrection($instituteId, (string) $user->getId(), $id, $rejectRequest);

            return $this->success('Attendance correction rejected successfully.');
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
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
}
