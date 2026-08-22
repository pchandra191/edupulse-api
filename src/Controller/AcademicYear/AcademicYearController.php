<?php
declare(strict_types=1);

namespace App\Controller\AcademicYear;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\AcademicYear\AcademicYearCreateRequestDTO;
use App\DTO\AcademicYear\AcademicYearFilterRequest;
use App\DTO\AcademicYear\AcademicYearResponseDTO;
use App\DTO\AcademicYear\AcademicYearUpdateRequestDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\AcademicYear\AcademicYearService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AcademicYearController extends AbstractApiController
{
    public function __construct(
        private AcademicYearService $academicYearService,
        private ValidatorInterface $validator,
        private Security $security,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/academic-years', name: 'academic_year_list', methods: ['GET'])]
    public function list(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $instituteId = $this->getCurrentInstituteId();

        $filter = new AcademicYearFilterRequest(
            search: $request->query->get('search'),
            status: $request->query->get('status'),
            page: max(1, (int) $request->query->get('page', 1)),
            limit: max(1, min(100, (int) $request->query->get('limit', 10))),
            sortBy: $request->query->get('sortBy', 'createdAt'),
            sortOrder: $request->query->get('sortOrder', 'DESC'),
        );

        $result = $this->academicYearService->getAcademicYears($instituteId, $filter);

        return $this->success('Academic years retrieved successfully.', $result);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/academic-years', name: 'academic_year_create', methods: ['POST'])]
    public function create(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $createRequest = new AcademicYearCreateRequestDTO(
            name: $data['name'] ?? '',
            code: $data['code'] ?? '',
            startDate: $data['startDate'] ?? '',
            endDate: $data['endDate'] ?? '',
            isActive: (bool) ($data['isActive'] ?? false),
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

            if ($instituteId === null) {
                if (!$createRequest->instituteId) {
                    return $this->unprocessableEntity('Validation failed.', ['instituteId' => ['Institute ID is required.']]);
                }

                try {
                    $instituteId = new Uuid($createRequest->instituteId);
                } catch (\InvalidArgumentException $e) {
                    return $this->unprocessableEntity('Validation failed.', ['instituteId' => ['Invalid UUID format.']]);
                }
            }

            $profile = $this->academicYearService->createAcademicYear($instituteId, $createRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->created('Academic year created successfully.', $profile);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/academic-years/{id}', name: 'academic_year_get', methods: ['GET'])]
    public function get(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $profile = $this->academicYearService->getAcademicYear($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->success('Academic year retrieved successfully.', $profile);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/academic-years/{id}', name: 'academic_year_update', methods: ['PUT'])]
    public function update(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $updateRequest = new AcademicYearUpdateRequestDTO(
            name: $data['name'] ?? null,
            code: $data['code'] ?? null,
            startDate: $data['startDate'] ?? null,
            endDate: $data['endDate'] ?? null,
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
            $profile = $this->academicYearService->updateAcademicYear($this->parseUuid($id), $instituteId, $updateRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->updated('Academic year updated successfully.', $profile);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/academic-years/{id}', name: 'academic_year_delete', methods: ['DELETE'])]
    public function delete(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $this->academicYearService->deleteAcademicYear($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->deleted('Academic year archived successfully.');
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

    private function parseUuid(string $id): Uuid
    {
        try {
            return new Uuid($id);
        } catch (\InvalidArgumentException $e) {
            throw new AuthenticationException('Academic year not found.', previous: $e);
        }
    }
}
