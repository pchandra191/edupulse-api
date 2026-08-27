<?php
declare(strict_types=1);

namespace App\DTO\UserProfile;

readonly class UserDocumentResponseDTO
{
    public function __construct(
        public string $id,
        public string $documentType,
        public string $fileName,
        public string $mimeType,
        public int $fileSize,
        public string $storageReference,
        public string $verificationStatus,
        public ?string $verifiedAt,
        public string $createdAt,
        public ?string $updatedAt,
        public ?string $downloadUrl = null,
    ) {
    }
}
