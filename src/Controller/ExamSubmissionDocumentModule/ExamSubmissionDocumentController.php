<?php
declare(strict_types=1);

namespace App\Controller\ExamSubmissionDocumentModule;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\ExamSubmissionDocumentModule\ExamSubmissionDocumentResponseDTO;
use App\DTO\ExamSubmissionDocumentModule\UploadExamDocumentRequestDTO;
use App\Exception\AuthenticationException;
use App\Service\ExamSubmissionDocumentModule\ExamSubmissionDocumentService;
use App\Service\Role\RolePermissionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ExamSubmissionDocumentController extends AbstractApiController
{
    public function __construct(
        private ExamSubmissionDocumentService $examSubmissionDocumentService,
        private RolePermissionService $rolePermissionService,
        private ValidatorInterface $validator,
        private Security $security,
        private ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    private function normalizePermissionCode(string $code): string
    {
        return str_replace('-', '_', mb_strtolower($code));
    }

    private function hasPermission(string $permissionCode): bool
    {
        $user = $this->security->getUser();

        if (!$user instanceof \App\Entity\User) {
            return false;
        }

        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        if (in_array('ROLE_STUDENT', $user->getRoles(), true)) {
            return true;
        }

        $effectivePermissions = $this->rolePermissionService->getUserEffectivePermissions($user->getId());

        return in_array($this->normalizePermissionCode($permissionCode), $effectivePermissions->permissions, true);
    }

    private function requirePermission(string $permissionCode): void
    {
        if (!$this->hasPermission($permissionCode)) {
            throw new AuthenticationException('Access denied.');
        }
    }

    #[SecurityAttribute("is_granted('ROLE_STUDENT') or is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-attempts/{attemptId}/documents', name: 'exam_document_upload', methods: ['POST'])]
    public function upload(Request $request, string $attemptId): JsonResponse
    {
        $this->requirePermission('exam_submission_documents.create');

        try {
            $uploadedFile = $request->files->get('file');

            if (!$uploadedFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                return $this->unprocessableEntity('File is required.', ['file' => ['File is required.']]);
            }

            $documentType = $request->request->get('documentType', 'ANSWER_SHEET');

            $dto = new UploadExamDocumentRequestDTO(
                file: $uploadedFile,
                documentType: (string) $documentType,
            );

            $errors = $this->validator->validate($dto);

            if (count($errors) > 0) {
                $formattedErrors = [];

                foreach ($errors as $error) {
                    $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
                }

                return $this->unprocessableEntity('Validation failed.', $formattedErrors);
            }

            $document = $this->examSubmissionDocumentService->uploadDocument(Uuid::fromString($attemptId), $dto);

            return $this->created('Document uploaded successfully.', $document);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessableEntity($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->error($e->getMessage());
        }
    }

    #[SecurityAttribute("is_granted('ROLE_STUDENT') or is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-attempts/{attemptId}/documents', name: 'exam_document_list', methods: ['GET'])]
    public function list(string $attemptId): JsonResponse
    {
        $this->requirePermission('exam_submission_documents.view');

        try {
            $documents = $this->examSubmissionDocumentService->getDocuments(Uuid::fromString($attemptId));

            return $this->success('Documents retrieved successfully.', ['documents' => $documents]);
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }
    }

    #[SecurityAttribute("is_granted('ROLE_STUDENT') or is_granted('ROLE_INSTITUTE') or is_granted('ROLE_SUPER_ADMIN')")]
    #[Route('/exam-attempts/{attemptId}/documents/{documentId}', name: 'exam_document_delete', methods: ['DELETE'])]
    public function delete(string $attemptId, string $documentId): JsonResponse
    {
        $this->requirePermission('exam_submission_documents.delete');

        try {
            $this->examSubmissionDocumentService->deleteDocument(Uuid::fromString($attemptId), Uuid::fromString($documentId));

            return $this->deleted('Document deleted successfully.');
        } catch (AuthenticationException $e) {
            return $this->notFound();
        }
    }
}
