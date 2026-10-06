<?php
declare(strict_types=1);

namespace App\DTO\Analysis;

use App\Enum\Analysis\AnalysisDocumentConstraints;
use Symfony\Component\Validator\Constraints as Assert;

readonly class UploadAnalysisDocumentRequestDTO
{
    public function __construct(
        #[Assert\NotBlank(message: 'Files are required.')]
        #[Assert\Count(min: 1, max: 100, minMessage: 'At least one file is required.', maxMessage: 'Cannot upload more than 100 files.')]
        #[Assert\All(
            constraints: [
                new Assert\NotBlank(message: 'File is required.'),
                new Assert\File(
                    maxSize: AnalysisDocumentConstraints::MAX_FILE_SIZE,
                    mimeTypes: [
                        'application/pdf',
                        'image/jpeg',
                        'image/png',
                        'image/webp',
                    ],
                    mimeTypesMessage: 'Invalid file type. Allowed: PDF, JPG, PNG, WEBP.',
                ),
            ],
        )]
        public array $files,

        #[Assert\NotBlank(message: 'Document type is required.')]
        #[Assert\Choice(choices: ['QUESTION_PAPER', 'ANSWER_SHEET'], message: 'Invalid document type. Must be QUESTION_PAPER or ANSWER_SHEET.')]
        public string $documentType,
    ) {
    }
}