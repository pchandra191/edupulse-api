<?php
declare(strict_types=1);

namespace App\DTO\Analysis;

use App\Enum\Analysis\AnalysisDocumentConstraints;
use Symfony\Component\Validator\Constraints as Assert;

readonly class UploadAnalysisDocumentRequestDTO
{
    public function __construct(
        #[Assert\NotBlank(message: 'File is required.')]
        #[Assert\File(
            maxSize: AnalysisDocumentConstraints::MAX_FILE_SIZE,
            mimeTypes: [
                'application/pdf',
                'image/jpeg',
                'image/png',
            ],
            mimeTypesMessage: 'Invalid file type. Allowed: PDF, JPG, PNG.',
        )]
        public \Symfony\Component\HttpFoundation\File\UploadedFile $file,

        #[Assert\NotBlank(message: 'Document type is required.')]
        #[Assert\Choice(choices: ['QUESTION_PAPER', 'ANSWER_SHEET'], message: 'Invalid document type. Must be QUESTION_PAPER or ANSWER_SHEET.')]
        public string $documentType,
    ) {
    }
}