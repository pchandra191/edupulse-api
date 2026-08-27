<?php
declare(strict_types=1);

namespace App\Controller\UserProfile;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\UserProfile\CreateUserGovernmentIdentifierRequestDTO;
use App\DTO\UserProfile\UpdateUserGovernmentIdentifierRequestDTO;
use App\DTO\UserProfile\UserGovernmentIdentifierResponseDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Repository\UserRepository;
use App\Service\UserProfile\ProfileAuthorizationService;
use App\Service\UserProfile\UserGovernmentIdentifierService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Uid\Uuid;

class UserGovernmentIdentifierController extends AbstractApiController
{
    public function __construct(
        private UserGovernmentIdentifierService $identifierService,
        private ProfileAuthorizationService $authService,
        private UserRepository $userRepository,
        private ValidatorInterface $validator,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/users/{id}/government-identifiers', name: 'user_gov_ids_list', methods: ['GET'])]
    public function list(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccessSensitive($user);
            $identifiers = $this->identifierService->listIdentifiers($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success('Government identifiers retrieved successfully.', $identifiers);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/users/{id}/government-identifiers', name: 'user_gov_ids_create', methods: ['POST'])]
    public function create(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccessSensitive($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        $createRequest = CreateUserGovernmentIdentifierRequestDTO::fromArray($data);

        $errors = $this->validator->validate($createRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $identifier = $this->identifierService->createIdentifier($user, $createRequest);
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to create government identifier. Please check your data and try again.');
        }

        return $this->created('Government identifier created successfully.', $identifier);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/users/{id}/government-identifiers/{identifierId}', name: 'user_gov_ids_update', methods: ['PUT'])]
    public function update(string $id, string $identifierId, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccessSensitive($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        $updateRequest = UpdateUserGovernmentIdentifierRequestDTO::fromArray($data);

        $errors = $this->validator->validate($updateRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $identifier = $this->identifierService->updateIdentifier($user, new Uuid($identifierId), $updateRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to update government identifier. Please check your data and try again.');
        }

        return $this->updated('Government identifier updated successfully.', $identifier);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/users/{id}/government-identifiers/{identifierId}/verify', name: 'user_gov_ids_verify', methods: ['POST'])]
    public function verify(string $id, string $identifierId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanVerify($user);
            $identifier = $this->identifierService->verifyIdentifier($user, new Uuid($identifierId));
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage(), code: 'ACCESS_DENIED');
        } catch (\InvalidArgumentException $e) {
            return $this->notFound($e->getMessage(), code: 'IDENTIFIER_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to verify government identifier. Please try again later.', code: 'VERIFICATION_ERROR');
        }

        return $this->updated('Government identifier verified successfully.', $identifier);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/users/{id}/government-identifiers/{identifierId}/reject', name: 'user_gov_ids_reject', methods: ['POST'])]
    public function reject(string $id, string $identifierId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanVerify($user);
            $identifier = $this->identifierService->rejectIdentifier($user, new Uuid($identifierId));
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage(), code: 'ACCESS_DENIED');
        } catch (\InvalidArgumentException $e) {
            return $this->notFound($e->getMessage(), code: 'IDENTIFIER_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to reject government identifier. Please try again later.', code: 'VERIFICATION_ERROR');
        }

        return $this->updated('Government identifier rejected successfully.', $identifier);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/users/{id}/government-identifiers/{identifierId}', name: 'user_gov_ids_delete', methods: ['DELETE'])]
    public function delete(string $id, string $identifierId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccessSensitive($user);
            $this->identifierService->deleteIdentifier($user, new Uuid($identifierId));
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to delete government identifier. Please try again later.');
        }

        return $this->deleted('Government identifier deleted successfully.');
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
