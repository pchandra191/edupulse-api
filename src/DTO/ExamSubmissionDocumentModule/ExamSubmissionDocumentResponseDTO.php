<?php
declare(strict_types=1);

namespace App\DTO\ExamSubmissionDocumentModule;

readonly class ExamSubmissionDocumentResponseDTO
{
    public function __construct(
        public string $id,
        public string $examAttemptId,
        public string $uploadedById,
        public string $uploadedByName,
        public string $originalFilename,
        public string $mimeType,
        public int $fileSize,
        public ?int $pageCount,
        public string $documentType,
        public string $processingStatus,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
