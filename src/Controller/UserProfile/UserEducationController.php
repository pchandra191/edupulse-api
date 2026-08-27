<?php
declare(strict_types=1);

namespace App\Controller\UserProfile;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\UserProfile\CreateUserEducationRequestDTO;
use App\DTO\UserProfile\UpdateUserEducationRequestDTO;
use App\DTO\UserProfile\UserEducationResponseDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Repository\UserRepository;
use App\Service\UserProfile\ProfileAuthorizationService;
use App\Service\UserProfile\UserEducationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Uid\Uuid;

class UserEducationController extends AbstractApiController
{
    public function __construct(
        private UserEducationService $educationService,
        private ProfileAuthorizationService $authService,
        private UserRepository $userRepository,
        private ValidatorInterface $validator,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/users/{id}/education', name: 'user_education_list', methods: ['GET'])]
    public function list(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccess($user);
            $records = $this->educationService->listEducation($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success('Education records retrieved successfully.', $records);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/users/{id}/education', name: 'user_education_create', methods: ['POST'])]
    public function create(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccess($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        $createRequest = CreateUserEducationRequestDTO::fromArray($data);

        $errors = $this->validator->validate($createRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $record = $this->educationService->createEducation($user, $createRequest);
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to create education record. Please check your data and try again.');
        }

        return $this->created('Education record created successfully.', $record);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/users/{id}/education/{educationId}', name: 'user_education_update', methods: ['PUT'])]
    public function update(string $id, string $educationId, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccess($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        $updateRequest = UpdateUserEducationRequestDTO::fromArray($data);

        $errors = $this->validator->validate($updateRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $record = $this->educationService->updateEducation($user, new Uuid($educationId), $updateRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to update education record. Please check your data and try again.');
        }

        return $this->updated('Education record updated successfully.', $record);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/users/{id}/education/{educationId}', name: 'user_education_delete', methods: ['DELETE'])]
    public function delete(string $id, string $educationId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccess($user);
            $this->educationService->deleteEducation($user, new Uuid($educationId));
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to delete education record. Please try again later.');
        }

        return $this->deleted('Education record deleted successfully.');
    }

    private function resolveUser(string $id): User
    {
        try {
            $uuid = new Uuid($id);
        } catch (\InvalidArgumentException $e) {
            throw new AuthenticationException('User not found.');
        }

        $user = $this->userRepository->find($uuid);

        if (!$user instanceof User) {
            throw new AuthenticationException('User not found.');
        }

        return $user;
    }
}
