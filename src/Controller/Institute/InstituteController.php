<?php
declare(strict_types=1);

namespace App\Controller\Institute;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\Institute\InstituteFilterRequest;
use App\DTO\Institute\InstituteOptionResponse;
use App\DTO\Institute\InstituteProfileResponseDTO;
use App\DTO\Institute\InstituteProfileUpdateRequestDTO;
use App\Exception\AuthenticationException;
use App\Common\Exception\BusinessException;
use App\Service\Institute\InstituteService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Uid\Uuid;

class InstituteController extends AbstractApiController
{
    public function __construct(
        private InstituteService $instituteService,
        private ValidatorInterface $validator,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[Route('/institutes/options', name: 'institute_options', methods: ['GET'])]
    public function options(): JsonResponse
    {
        $options = $this->instituteService->getOptions();

        return $this->success('Institutes retrieved successfully.', $options);
    }

    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[Route('/institutes', name: 'institute_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $filter = new InstituteFilterRequest(
            search: $request->query->get('search'),
            page: max(1, (int) $request->query->get('page', 1)),
            limit: max(1, min(100, (int) $request->query->get('limit', 10))),
            sortBy: $request->query->get('sortBy', 'createdAt'),
            sortOrder: $request->query->get('sortOrder', 'DESC'),
        );

        $result = $this->instituteService->getInstitutes($filter);

        return $this->paginated('Institutes retrieved successfully.', $result->items, $result->pagination);
    }

    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[Route('/institutes/{id}', name: 'institute_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        try {
            $institute = $this->instituteService->getById($this->parseUuid($id));
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success('Institute retrieved successfully.', $institute);
    }

    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[Route('/institutes/{id}', name: 'institute_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $updateRequest = new InstituteProfileUpdateRequestDTO(
            name: $data['name'] ?? '',
            email: $data['email'] ?? '',
            phone: $data['phone'] ?? null,
            website: $data['website'] ?? null,
            logo: $data['logo'] ?? null,
            addressLine1: $data['addressLine1'] ?? null,
            addressLine2: $data['addressLine2'] ?? null,
            city: $data['city'] ?? null,
            state: $data['state'] ?? null,
            country: $data['country'] ?? null,
            postalCode: $data['postalCode'] ?? null,
            timezone: $data['timezone'] ?? null,
            currency: $data['currency'] ?? null,
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
            $institute = $this->instituteService->updateInstitute($this->parseUuid($id), $updateRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (BusinessException $e) {
            return $this->error($e->getMessage());
        }

        return $this->updated('Institute updated successfully.', $institute);
    }

    private function parseUuid(string $id): Uuid
    {
        try {
            return new Uuid($id);
        } catch (\InvalidArgumentException $e) {
            throw new AuthenticationException('Institute not found.', previous: $e);
        }
    }
}
