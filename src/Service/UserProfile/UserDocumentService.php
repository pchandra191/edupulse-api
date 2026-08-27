<?php
declare(strict_types=1);

namespace App\Service\UserProfile;

use App\DTO\UserProfile\CreateUserDocumentRequestDTO;
use App\DTO\UserProfile\UserDocumentResponseDTO;
use App\Entity\User;
use App\Entity\UserDocument;
use App\Enum\VerificationStatus;
use App\Repository\UserDocumentRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

readonly class UserDocumentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserDocumentRepository $userDocumentRepository,
        private LoggerInterface $logger,
        private string $projectDir,
    ) {
    }

    public function listDocuments(User $user): array
    {
        $documents = $this->userDocumentRepository->findByUserId($user->getId());

        return array_map(
            fn(UserDocument $document) => $this->mapToResponse($document),
            $documents
        );
    }

    public function createDocument(User $user, CreateUserDocumentRequestDTO $request): UserDocumentResponseDTO
    {
        $relativePath = $this->generateDocumentPath($user, $request);
        $absolutePath = $this->getUploadDirectory() . '/' . $relativePath;

        if ($request->file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $uploadDir = dirname($absolutePath);
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                throw new \RuntimeException(sprintf('Unable to create upload directory: %s', $uploadDir));
            }
            $request->file->move($uploadDir, basename($absolutePath));
        }

        $document = new UserDocument();
        $document->setUserId($user->getId());
        $document->setDocumentType(\App\Enum\UserDocumentType::from($request->documentType));
        $document->setFileName($request->fileName);
        $document->setMimeType($request->mimeType);
        $document->setFileSize($request->fileSize);
        $document->setStorageReference($relativePath);
        $document->setVerificationStatus(VerificationStatus::Unverified);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $this->mapToResponse($document);
    }

    public function deleteDocument(User $user, Uuid $documentId): void
    {
        $document = $this->findUserDocument($user, $documentId);

        $this->removeFileIfExists($document->getStorageReference());

        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    public function verifyDocument(User $user, Uuid $documentId): UserDocumentResponseDTO
    {
        $document = $this->findUserDocument($user, $documentId);
        $document->setVerificationStatus(VerificationStatus::Verified);
        $this->entityManager->flush();

        return $this->mapToResponse($document);
    }

    public function rejectDocument(User $user, Uuid $documentId): UserDocumentResponseDTO
    {
        $document = $this->findUserDocument($user, $documentId);
        $document->setVerificationStatus(VerificationStatus::Rejected);
        $this->entityManager->flush();

        return $this->mapToResponse($document);
    }

    public function serveDocument(UserDocument $document): BinaryFileResponse
    {
        $filePath = $this->getUploadDirectory() . '/' . $document->getStorageReference();

        if (!file_exists($filePath)) {
            throw new \RuntimeException('Document file not found.');
        }

        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', $document->getMimeType());
        $response->headers->set('Content-Disposition', 'inline; filename="' . $document->getFileName() . '"');

        return $response;
    }

    public function findUserDocument(User $user, Uuid $documentId): UserDocument
    {
        $document = $this->userDocumentRepository->find($documentId);

        if (!$document instanceof UserDocument) {
            throw new \App\Exception\AuthenticationException('Document not found.');
        }

        if (!$document->getUserId()->equals($user->getId())) {
            throw new \App\Exception\AuthenticationException('Document not found.');
        }

        return $document;
    }

    private function getUploadDirectory(): string
    {
        return rtrim($_ENV['USER_DOCUMENT_UPLOAD_DIR'] ?? ($_SERVER['APP_ENV'] === 'test' ? sys_get_temp_dir() : $this->projectDir . '/public/uploads/user-documents'), '/');
    }

    private function generateDocumentPath(User $user, CreateUserDocumentRequestDTO $request): string
    {
        $extension = pathinfo($request->fileName, PATHINFO_EXTENSION);
        $filename = Uuid::v7()->toRfc4122() . ($extension ? '.' . $extension : '');

        return 'user_documents/' . $user->getId()->toRfc4122() . '/' . $filename;
    }

    private function removeFileIfExists(string $storageReference): void
    {
        $filePath = $this->getUploadDirectory() . '/' . $storageReference;

        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    private function mapToResponse(UserDocument $document): UserDocumentResponseDTO
    {
        return new UserDocumentResponseDTO(
            id: $document->getId()->toRfc4122(),
            documentType: $document->getDocumentType()->value,
            fileName: $document->getFileName(),
            mimeType: $document->getMimeType(),
            fileSize: $document->getFileSize(),
            storageReference: basename($document->getStorageReference()),
            verificationStatus: $document->getVerificationStatus()->value,
            verifiedAt: $document->getVerifiedAt()?->format(DateTimeInterface::ATOM),
            createdAt: $document->getCreatedAt()->format(DateTimeInterface::ATOM),
            updatedAt: $document->getUpdatedAt()?->format(DateTimeInterface::ATOM),
            downloadUrl: '/api/v1/users/' . $document->getUserId()->toRfc4122() . '/documents/' . $document->getId()->toRfc4122() . '/download',
        );
    }
}
