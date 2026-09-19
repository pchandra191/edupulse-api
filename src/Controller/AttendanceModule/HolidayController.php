<?php
declare(strict_types=1);

namespace App\Controller\AttendanceModule;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\Common\Api\Response\PaginationResponse;
use App\DTO\AttendanceModule\HolidayCreateRequestDTO;
use App\DTO\AttendanceModule\HolidayFilterRequest;
use App\DTO\AttendanceModule\HolidayUpdateRequestDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\AttendanceModule\HolidayService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class HolidayController extends AbstractApiController
{
    public function __construct(
        private HolidayService $holidayService,
        private ValidatorInterface $validator,
        private Security $security,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/holidays', name: 'holiday_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $filter = new HolidayFilterRequest(
            academicYearId: $request->query->get('academicYearId'),
            date: $request->query->get('date'),
            enabled: $request->query->get('enabled'),
            page: max(1, (int) $request->query->get('page', 1)),
            limit: max(1, min(100, (int) $request->query->get('limit', 10))),
            sortBy: $request->query->get('sortBy', 'holidayDate'),
            sortOrder: $request->query->get('sortOrder', 'ASC'),
        );

        try {
            $instituteId = $this->getCurrentInstituteId();
            $result = $this->holidayService->getHolidays($instituteId, $filter);

            return $this->paginated('Holidays retrieved successfully.', $result->items, new PaginationResponse(
                page: $result->pagination['page'],
                limit: $result->pagination['limit'],
                totalItems: $result->pagination['totalItems'],
                totalPages: $result->pagination['totalPages'],
            ));
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/holidays', name: 'holiday_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $createRequest = new HolidayCreateRequestDTO(
            date: (string) ($data['date'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            description: $data['description'] ?? null,
            enabled: (bool) ($data['enabled'] ?? true),
            academicYearId: $data['academicYearId'] ?? null,
            academicClassId: $data['academicClassId'] ?? null,
            sectionId: $data['sectionId'] ?? null,
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
            $result = $this->holidayService->createHoliday($instituteId, $createRequest);

            return $this->created('Holiday created successfully.', $result);
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/holidays/{id}', name: 'holiday_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $result = $this->holidayService->getHoliday($instituteId, $id);

            return $this->success('Holiday retrieved successfully.', $result);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/holidays/{id}', name: 'holiday_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $updateRequest = new HolidayUpdateRequestDTO(
            date: $data['date'] ?? null,
            name: $data['name'] ?? null,
            description: $data['description'] ?? null,
            enabled: isset($data['enabled']) ? (bool) $data['enabled'] : null,
            academicYearId: $data['academicYearId'] ?? null,
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
            $result = $this->holidayService->updateHoliday($instituteId, $id, $updateRequest);

            return $this->updated('Holiday updated successfully.', $result);
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/holidays/{id}', name: 'holiday_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $this->holidayService->deleteHoliday($instituteId, $id);

            return $this->deleted('Holiday deleted successfully.');
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage());
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
