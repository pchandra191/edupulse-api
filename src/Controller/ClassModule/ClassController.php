<?php
declare(strict_types=1);

namespace App\Controller\ClassModule;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\ClassModule\ClassFilterRequest;
use App\DTO\ClassModule\ClassResponseDTO;
use App\DTO\ClassModule\CreateClassRequestDTO;
use App\DTO\ClassModule\UpdateClassRequestDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Service\ClassModule\ClassService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ClassController extends AbstractApiController
{
    public function __construct(
        private ClassService $classService,
        private ValidatorInterface $validator,
        private Security $security,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/classes', name: 'class_list', methods: ['GET'])]
    public function list(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $instituteId = $this->getCurrentInstituteId();

        $filter = new ClassFilterRequest(
            search: $request->query->get('search'),
            academicYearId: $request->query->get('academicYearId'),
            status: $request->query->get('status'),
            page: max(1, (int) $request->query->get('page', 1)),
            limit: max(1, min(100, (int) $request->query->get('limit', 10))),
            sortBy: $request->query->get('sortBy', 'createdAt'),
            sortOrder: $request->query->get('sortOrder', 'DESC'),
        );

        $result = $this->classService->getClasses($instituteId, $filter);

        return $this->success('Classes retrieved successfully.', $result);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/classes', name: 'class_create', methods: ['POST'])]
    public function create(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $createRequest = new CreateClassRequestDTO(
            academicYearId: (string) ($data['academicYearId'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            code: (string) ($data['code'] ?? ''),
            description: $data['description'] ?? null,
            status: (string) ($data['status'] ?? 'ACTIVE'),
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

            $class = $this->classService->createClass($instituteId, $createRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->created('Class created successfully.', $class);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/classes/{id}', name: 'class_get', methods: ['GET'])]
    public function get(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $class = $this->classService->getClass($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->success('Class retrieved successfully.', $class);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/classes/{id}', name: 'class_update', methods: ['PUT'])]
    public function update(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $updateRequest = new UpdateClassRequestDTO(
            name: $data['name'] ?? null,
            code: $data['code'] ?? null,
            description: $data['description'] ?? null,
            status: $data['status'] ?? null,
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
            $class = $this->classService->updateClass($this->parseUuid($id), $instituteId, $updateRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        }

        return $this->updated('Class updated successfully.', $class);
    }

    #[SecurityAttribute("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/classes/{id}', name: 'class_delete', methods: ['DELETE'])]
    public function delete(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $instituteId = $this->getCurrentInstituteId();
            $this->classService->deleteClass($this->parseUuid($id), $instituteId);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }

        return $this->deleted('Class archived successfully.');
    }

    private function getCurrentInstituteId(): ?Uuid
    {
        $user = $this->security->getUser();

        if (!$user instanceof \App\Entity\User) {
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
            throw new AuthenticationException('Class not found.', previous: $e);
        }
    }
}
