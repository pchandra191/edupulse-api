<?php
declare(strict_types=1);

namespace App\Service\Institute;

use App\Entity\Institute;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

readonly class InstituteLogoService
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 MB

    public function __construct(
        private LoggerInterface $logger,
        private string $projectDir,
    ) {
    }

    public function uploadLogo(Institute $institute, UploadedFile $file): string
    {
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File size exceeds the maximum limit of 5 MB.');
        }

        $mimeType = $file->getMimeType();

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid file type. Allowed: JPEG, PNG, WEBP.');
        }

        $filename = 'logo.jpg';
        $relativePath = $institute->getId()->toRfc4122() . '/' . $filename;
        $uploadDir = $this->getUploadDirectory($institute->getId());

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new \RuntimeException(sprintf('Unable to create upload directory: %s', $uploadDir));
        }

        $destination = $uploadDir . '/' . $filename;

        try {
            if ($mimeType === 'image/png') {
                $this->convertPngToJpg($file->getPathname(), $destination);
            } elseif ($mimeType === 'image/webp') {
                $this->convertWebpToJpg($file->getPathname(), $destination);
            } else {
                $file->move($uploadDir, $filename);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Logo conversion failed, falling back to original file.', [
                'mime_type' => $mimeType,
                'error' => $e->getMessage(),
            ]);

            $originalExtension = match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => $file->getClientOriginalExtension(),
            };

            $filename = 'logo.' . $originalExtension;
            $relativePath = $institute->getId()->toRfc4122() . '/' . $filename;
            $file->move($uploadDir, $filename);
        }

        return $relativePath;
    }

    public function removeOldLogo(?string $relativePath): void
    {
        if ($relativePath === null) {
            return;
        }

        $filePath = $this->getBaseDirectory() . '/' . $relativePath;

        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    public function getLogoUrl(?string $relativePath): ?string
    {
        if ($relativePath === null) {
            return null;
        }

        return '/uploads/institutes/' . $relativePath;
    }

    private function getUploadDirectory(Uuid $instituteId): string
    {
        return $this->getBaseDirectory() . '/' . $instituteId->toRfc4122();
    }

    private function getBaseDirectory(): string
    {
        return rtrim($_ENV['INSTITUTE_LOGO_UPLOAD_DIR'] ?? ($_SERVER['APP_ENV'] === 'test' ? sys_get_temp_dir() : $this->projectDir . '/public/uploads/institutes'), '/');
    }

    private function convertPngToJpg(string $sourcePath, string $destinationPath): void
    {
        if (!file_exists($sourcePath)) {
            throw new \RuntimeException('Source PNG file not found: ' . $sourcePath);
        }

        $source = @imagecreatefrompng($sourcePath);

        if ($source === false) {
            throw new \RuntimeException('Unable to read PNG image. File may be corrupted or not a valid PNG.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $destination = imagecreatetruecolor($width, $height);

        if ($destination === false) {
            imagedestroy($source);
            throw new \RuntimeException('Unable to create JPG image.');
        }

        $white = imagecolorallocate($destination, 255, 255, 255);
        imagefilledrectangle($destination, 0, 0, $width, $height, $white);
        imagecopy($destination, $source, 0, 0, 0, 0, $width, $height);

        $saved = imagejpeg($destination, $destinationPath, 85);

        imagedestroy($source);
        imagedestroy($destination);

        if (!$saved) {
            throw new \RuntimeException('Unable to save JPG image to destination.');
        }
    }

    private function convertWebpToJpg(string $sourcePath, string $destinationPath): void
    {
        if (!file_exists($sourcePath)) {
            throw new \RuntimeException('Source WEBP file not found: ' . $sourcePath);
        }

        $source = @imagecreatefromwebp($sourcePath);

        if ($source === false) {
            throw new \RuntimeException('Unable to read WEBP image. File may be corrupted or not a valid WEBP.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $destination = imagecreatetruecolor($width, $height);

        if ($destination === false) {
            imagedestroy($source);
            throw new \RuntimeException('Unable to create JPG image.');
        }

        $white = imagecolorallocate($destination, 255, 255, 255);
        imagefilledrectangle($destination, 0, 0, $width, $height, $white);
        imagecopy($destination, $source, 0, 0, 0, 0, $width, $height);

        $saved = imagejpeg($destination, $destinationPath, 85);

        imagedestroy($source);
        imagedestroy($destination);

        if (!$saved) {
            throw new \RuntimeException('Unable to save JPG image to destination.');
        }
    }
}
