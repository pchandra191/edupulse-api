<?php
declare(strict_types=1);

namespace App\Controller\UserProfile;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\UserProfile\CreateUserDocumentRequestDTO;
use App\DTO\UserProfile\UserDocumentResponseDTO;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Repository\UserRepository;
use App\Service\UserProfile\ProfileAuthorizationService;
use App\Service\UserProfile\UserDocumentService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Uid\Uuid;

class UserDocumentController extends AbstractApiController
{
    public function __construct(
        private UserDocumentService $documentService,
        private ProfileAuthorizationService $authService,
        private UserRepository $userRepository,
        private ValidatorInterface $validator,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/users/{id}/documents', name: 'user_documents_list', methods: ['GET'])]
    public function list(string $id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccess($user);
            $documents = $this->documentService->listDocuments($user);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        }

        return $this->success('Documents retrieved successfully.', $documents);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/users/{id}/documents', name: 'user_documents_create', methods: ['POST'])]
    public function create(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $uploadedFile = $request->files->get('file');

        if (!$uploadedFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $data = json_decode($request->getContent(), true);
            if (!is_array($data)) {
                return $this->unprocessableEntity('Validation failed.', ['file' => ['File upload is required.']], code: 'VALIDATION_ERROR');
            }
            $createRequest = CreateUserDocumentRequestDTO::fromArray($data);
        } else {
            $data = json_decode($request->getContent(), true) ?: [];
            $createRequest = CreateUserDocumentRequestDTO::fromArray($data, $uploadedFile);
        }

        $errors = $this->validator->validate($createRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors, code: 'VALIDATION_ERROR');
        }

        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccess($user);
            $document = $this->documentService->createDocument($user, $createRequest);
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage(), code: 'USER_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to create document. Please check your data and try again.', code: 'DOCUMENT_CREATE_ERROR');
        }

        return $this->created('Document created successfully.', $document);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER') or is_granted('ROLE_STUDENT')")]
    #[Route('/users/{id}/documents/{documentId}/download', name: 'user_documents_download', methods: ['GET'])]
    public function download(string $id, string $documentId): Response
    {
        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccess($user);
            $document = $this->documentService->findUserDocument($user, new Uuid($documentId));
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage(), code: 'USER_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to download document.', code: 'DOCUMENT_DOWNLOAD_ERROR');
        }

        try {
            return $this->documentService->serveDocument($document);
        } catch (\RuntimeException $e) {
            return $this->serverError('Document file not found on server.', code: 'DOCUMENT_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to download document.', code: 'DOCUMENT_DOWNLOAD_ERROR');
        }
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN') or is_granted('ROLE_TEACHER')")]
    #[Route('/users/{id}/documents/{documentId}', name: 'user_documents_delete', methods: ['DELETE'])]
    public function delete(string $id, string $documentId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanAccess($user);
            $this->documentService->deleteDocument($user, new Uuid($documentId));
        } catch (AuthenticationException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            return $this->serverError('Unable to delete document. Please try again later.');
        }

        return $this->deleted('Document deleted successfully.');
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/users/{id}/documents/{documentId}/verify', name: 'user_documents_verify', methods: ['POST'])]
    public function verify(string $id, string $documentId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanVerify($user);
            $document = $this->documentService->verifyDocument($user, new Uuid($documentId));
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage(), code: 'ACCESS_DENIED');
        } catch (\InvalidArgumentException $e) {
            return $this->notFound($e->getMessage(), code: 'DOCUMENT_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to verify document. Please try again later.', code: 'VERIFICATION_ERROR');
        }

        return $this->updated('Document verified successfully.', $document);
    }

    #[Security("is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/users/{id}/documents/{documentId}/reject', name: 'user_documents_reject', methods: ['POST'])]
    public function reject(string $id, string $documentId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->resolveUser($id);
            $this->authService->assertCanVerify($user);
            $document = $this->documentService->rejectDocument($user, new Uuid($documentId));
        } catch (AuthenticationException $e) {
            return $this->accessDenied($e->getMessage(), code: 'ACCESS_DENIED');
        } catch (\InvalidArgumentException $e) {
            return $this->notFound($e->getMessage(), code: 'DOCUMENT_NOT_FOUND');
        } catch (\Throwable $e) {
            return $this->serverError('Unable to reject document. Please try again later.', code: 'VERIFICATION_ERROR');
        }

        return $this->updated('Document rejected successfully.', $document);
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
