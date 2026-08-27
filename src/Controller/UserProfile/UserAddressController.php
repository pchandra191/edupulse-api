<?php
declare(strict_types=1);

namespace App\Controller\UserProfile;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\UserProfile\CreateUserAddressRequestDTO;
use App\DTO\UserProfile\UpdateUserAddressRequestDTO;
use App\DTO\UserProfile\UserAddressResponseDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Repository\UserRepository;
use App\Service\UserProfile\ProfileAuthorizationService;
use App\Service\UserProfile\UserAddressService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Uid\Uuid;

class UserAddressController extends AbstractApiController
{
    public function __construct(
        private UserAddressService $addressService,
        private ProfileAuthorizationService $authService,
        private UserRepository $userRepository,
        private ValidatorInterface $validator,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/users/{id}/addresses', name: 'user_addresses_list', methods: ['GET'])]
    public function list(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccess($user);
            $addresses = $this->addressService->listAddresses($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success('Addresses retrieved successfully.', $addresses);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/users/{id}/addresses', name: 'user_addresses_create', methods: ['POST'])]
    public function create(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.', code: 'INVALID_JSON');
        }

        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccess($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage(), code: 'USER_NOT_FOUND');
        }

        $createRequest = CreateUserAddressRequestDTO::fromArray($data);

        $errors = $this->validator->validate($createRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors, code: 'VALIDATION_ERROR');
        }

        try {
            $address = $this->addressService->createAddress($user, $createRequest);
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage(), code: 'ADDRESS_CREATE_FAILED');
        } catch (\RuntimeException $e) {
            return $this->serverError('Unable to create address. Please check your data and try again.', code: 'ADDRESS_CREATE_ERROR');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to create address. Please try again later.', code: 'ADDRESS_CREATE_ERROR');
        }

        return $this->created('Address created successfully.', $address);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/users/{id}/addresses/{addressId}', name: 'user_addresses_update', methods: ['PUT'])]
    public function update(string $id, string $addressId, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.', code: 'INVALID_JSON');
        }

        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccess($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage(), code: 'USER_NOT_FOUND');
        }

        $updateRequest = UpdateUserAddressRequestDTO::fromArray($data);

        $errors = $this->validator->validate($updateRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors, code: 'VALIDATION_ERROR');
        }

        try {
            $address = $this->addressService->updateAddress($user, new Uuid($addressId), $updateRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage(), code: 'ADDRESS_NOT_FOUND');
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage(), code: 'ADDRESS_UPDATE_FAILED');
        } catch (\RuntimeException $e) {
            return $this->serverError('Unable to update address. Please check your data and try again.', code: 'ADDRESS_UPDATE_ERROR');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to update address. Please try again later.', code: 'ADDRESS_UPDATE_ERROR');
        }

        return $this->updated('Address updated successfully.', $address);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/users/{id}/addresses/{addressId}', name: 'user_addresses_delete', methods: ['DELETE'])]
    public function delete(string $id, string $addressId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccess($user);
            $this->addressService->deleteAddress($user, new Uuid($addressId));
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage(), code: 'ADDRESS_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to delete address. Please try again later.', code: 'ADDRESS_DELETE_ERROR');
        }

        return $this->deleted('Address deleted successfully.');
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
