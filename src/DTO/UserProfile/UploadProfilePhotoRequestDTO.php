<?php
declare(strict_types=1);

namespace App\DTO\UserProfile;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UploadProfilePhotoRequestDTO
{
    public function __construct(
        #[Assert\NotBlank(message: 'Profile photo is required.')]
        #[Assert\File(
            maxSize: '2M',
            mimeTypes: [
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/gif',
            ],
            mimeTypesMessage: 'Invalid file type. Allowed: JPEG, PNG, WEBP, GIF.',
        )]
        public \Symfony\Component\HttpFoundation\File\UploadedFile $file,
    ) {
    }
}
