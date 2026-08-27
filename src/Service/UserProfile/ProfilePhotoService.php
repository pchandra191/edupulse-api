<?php
declare(strict_types=1);

namespace App\Service\UserProfile;

use App\Entity\User;
use App\Entity\UserProfile;
use App\Exception\AuthenticationException;
use App\Repository\UserProfileRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly class ProfilePhotoService
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    private const MAX_FILE_SIZE = 2 * 1024 * 1024; // 2 MB

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserProfileRepository $userProfileRepository,
        private LoggerInterface $logger,
        private string $projectDir,
    ) {
    }

    public function uploadPhoto(User $user, UploadedFile $file): string
    {
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File size exceeds the maximum limit of 2 MB.');
        }

        $mimeType = $file->getMimeType();

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid file type. Allowed: JPEG, PNG, WEBP, GIF.');
        }

        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => $file->getClientOriginalExtension(),
        };

        $filename = Uuid::v7()->toRfc4122() . '.' . $extension;
        $relativePath = 'profile_photos/' . $user->getId()->toRfc4122() . '/' . $filename;
        $uploadDir = $this->getUploadDirectory($user->getId());

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new \RuntimeException(sprintf('Unable to create upload directory: %s', $uploadDir));
        }

        $file->move($uploadDir, $filename);

        $profile = $this->userProfileRepository->findByUserId($user->getId());

        if (!$profile instanceof UserProfile) {
            $profile = new UserProfile();
            $profile->setUserId($user->getId());
            $this->entityManager->persist($profile);
        } else {
            $this->removeFileIfExists($profile->getProfilePhotoReference());
        }

        $profile->setProfilePhotoReference($relativePath);
        $this->entityManager->flush();

        return $relativePath;
    }

    public function removePhoto(User $user): void
    {
        $profile = $this->userProfileRepository->findByUserId($user->getId());

        if (!$profile instanceof UserProfile) {
            return;
        }

        $reference = $profile->getProfilePhotoReference();
        $profile->setProfilePhotoReference(null);
        $this->entityManager->flush();

        $this->removeFileIfExists($reference);
    }

    public function getProfilePhoto(User $user): ?string
    {
        $profile = $this->userProfileRepository->findByUserId($user->getId());

        if (!$profile instanceof UserProfile) {
            return null;
        }

        return $profile->getProfilePhotoReference();
    }

    public function getAbsolutePath(?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }

        $baseDir = rtrim($_ENV['PROFILE_PHOTO_UPLOAD_DIR'] ?? ($_SERVER['APP_ENV'] === 'test' ? sys_get_temp_dir() : $this->projectDir . '/public/uploads/profile-photos'), '/');

        return $baseDir . '/' . $reference;
    }

    private function getUploadDirectory(Uuid $userId): string
    {
        $baseDir = rtrim($_ENV['PROFILE_PHOTO_UPLOAD_DIR'] ?? ($_SERVER['APP_ENV'] === 'test' ? sys_get_temp_dir() : $this->projectDir . '/public/uploads/profile-photos'), '/');

        return $baseDir . '/profile_photos/' . $userId->toRfc4122();
    }

    private function removeFileIfExists(?string $reference): void
    {
        if ($reference === null) {
            return;
        }

        $baseDir = rtrim($_ENV['PROFILE_PHOTO_UPLOAD_DIR'] ?? ($_SERVER['APP_ENV'] === 'test' ? sys_get_temp_dir() : $this->projectDir . '/public/uploads/profile-photos'), '/');
        $filePath = $baseDir . '/' . $reference;

        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }
}
