<?php
declare(strict_types=1);

namespace App\Controller\UserProfile;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\UserProfile\UpdateUserProfileRequestDTO;
use App\DTO\UserProfile\UserProfileResponseDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Repository\UserRepository;
use App\Service\UserProfile\ProfileAuthorizationService;
use App\Service\UserProfile\UserProfileService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Uid\Uuid;

class UserProfileController extends AbstractApiController
{
    public function __construct(
        private UserProfileService $profileService,
        private ProfileAuthorizationService $authService,
        private UserRepository $userRepository,
        private ValidatorInterface $validator,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/users/{id}/profile', name: 'user_profile_get', methods: ['GET'])]
    public function get(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccess($user);
            $profile = $this->profileService->getProfile($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success('Profile retrieved successfully.', $profile);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/users/{id}/profile', name: 'user_profile_update', methods: ['PUT'])]
    public function update(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
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

        $updateRequest = UpdateUserProfileRequestDTO::fromArray($data);

        $errors = $this->validator->validate($updateRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $profile = $this->profileService->updateProfile($user, $updateRequest);
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to update profile. Please check your data and try again.');
        }

        return $this->updated('Profile updated successfully.', $profile);
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
