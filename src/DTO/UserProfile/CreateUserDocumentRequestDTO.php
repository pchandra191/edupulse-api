<?php
declare(strict_types=1);

namespace App\DTO\UserProfile;

use App\Enum\UserDocumentType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateUserDocumentRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['PROFILE_PHOTO', 'AADHAAR', 'PAN', 'DRIVING_LICENCE', 'PASSPORT', 'VOTER_ID', 'EDUCATION_CERTIFICATE', 'EXPERIENCE_CERTIFICATE', 'ADDRESS_PROOF', 'OTHER'])]
        public string $documentType,

        #[Assert\NotBlank]
        public string $fileName,

        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $mimeType,

        #[Assert\NotBlank]
        #[Assert\Positive]
        public int $fileSize,

        #[Assert\Length(max: 500)]
        public ?string $storageReference = null,

        public ?UploadedFile $file = null,
    ) {
    }

    public static function fromArray(array $data, ?UploadedFile $file = null): self
    {
        return new self(
            documentType: $data['documentType'] ?? '',
            fileName: $data['fileName'] ?? '',
            mimeType: $data['mimeType'] ?? '',
            fileSize: (int) ($data['fileSize'] ?? 0),
            storageReference: self::normalizeString($data['storageReference'] ?? null),
            file: $file,
        );
    }

    private static function normalizeString(?string $value): ?string
    {
        if ($value === '' || $value === null) {
            return null;
        }

        return $value;
    }
}
