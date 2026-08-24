<?php
declare(strict_types=1);

namespace App\DTO\ExamSubmissionDocumentModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UploadExamDocumentRequestDTO
{
    public function __construct(
        #[Assert\NotBlank(message: 'File is required.')]
        #[Assert\File(
            maxSize: '10M',
            mimeTypes: [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'image/webp',
            ],
            mimeTypesMessage: 'Invalid file type. Allowed: PDF, JPG, PNG, WEBP.',
        )]
        public \Symfony\Component\HttpFoundation\File\UploadedFile $file,

        #[Assert\Choice(choices: ['ANSWER_SHEET', 'SUPPLEMENT'], message: 'Invalid document type.')]
        public ?string $documentType = 'ANSWER_SHEET',
    ) {
    }
}
