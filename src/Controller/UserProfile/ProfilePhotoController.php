<?php
declare(strict_types=1);

namespace App\Controller\UserProfile;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\UserProfile\UploadProfilePhotoRequestDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Repository\UserRepository;
use App\Service\UserProfile\ProfileAuthorizationService;
use App\Service\UserProfile\ProfilePhotoService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Uid\Uuid;

class ProfilePhotoController extends AbstractApiController
{
    public function __construct(
        private ProfilePhotoService $profilePhotoService,
        private ProfileAuthorizationService $authService,
        private UserRepository $userRepository,
        private ValidatorInterface $validator,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/users/{id}/profile-photo', name: 'user_profile_photo_upload', methods: ['POST'])]
    public function upload(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $uploadedFile = $request->files->get('file');

        if (!$uploadedFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            return $this->unprocessableEntity('Profile photo is required.', ['file' => ['Profile photo is required.']]);
        }

        try {
            $user = $this->resolveUser($id);
            $this->assertCanModifyPhoto($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        $dto = new UploadProfilePhotoRequestDTO(file: $uploadedFile);

        $errors = $this->validator->validate($dto);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $storageReference = $this->profilePhotoService->uploadPhoto($user, $dto->file);
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to upload profile photo. Please try again later.');
        }

        return $this->success('Profile photo uploaded successfully.', [
            'profilePhotoReference' => $storageReference,
        ]);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/users/{id}/profile-photo', name: 'user_profile_photo_serve', methods: ['GET'])]
    public function serve(string $id): BinaryFileResponse|Response
    {
        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccess($user);
            $profile = $this->profilePhotoService->getProfilePhoto($user);
        } catch (AuthenticationException $e) {
            return new Response('Profile photo not found.', Response::HTTP_NOT_FOUND);
        }

        if (!$profile) {
            return new Response('Profile photo not found.', Response::HTTP_NOT_FOUND);
        }

        $filePath = $this->profilePhotoService->getAbsolutePath($profile);

        if (!file_exists($filePath)) {
            return new Response('Profile photo file not found.', Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', mime_content_type($filePath));
        $response->headers->set('Content-Disposition', 'inline; filename="' . basename($filePath) . '"');

        return $response;
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/users/{id}/profile-photo', name: 'user_profile_photo_delete', methods: ['DELETE'])]
    public function delete(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->resolveUser($id);
            $this->assertCanModifyPhoto($user);
            $this->profilePhotoService->removePhoto($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to remove profile photo. Please try again later.');
        }

        return $this->deleted('Profile photo removed successfully.');
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

    private function assertCanModifyPhoto(User $targetUser): void
    {
        $this->authService->assertCanAccess($targetUser);
    }
}
