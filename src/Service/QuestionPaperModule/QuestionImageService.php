<?php
declare(strict_types=1);

namespace App\Service\QuestionPaperModule;

use App\Entity\Question;
use App\Entity\QuestionPaper;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

readonly class QuestionImageService
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    public function __construct(
        private LoggerInterface $logger,
        private string $projectDir,
    ) {
    }

    public function uploadImage(Question $question, UploadedFile $file): string
    {
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('Image file size exceeds the maximum limit of 5 MB.');
        }

        $mimeType = $file->getMimeType();

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid image file type. Allowed: JPEG, PNG, WEBP, GIF.');
        }

        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => $file->getClientOriginalExtension(),
        };

        $filename = Uuid::v7()->toRfc4122() . '.' . $extension;

        $paper = $question->getQuestionPaper();
        $instituteId = $paper->getInstitute()->getId();

        $relativePath = sprintf(
            '%s/%s/%s/%s',
            $instituteId->toRfc4122(),
            $paper->getId()->toRfc4122(),
            $question->getId()->toRfc4122(),
            $filename
        );

        $uploadDir = $this->getBaseDirectory() . '/' . dirname($relativePath);

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new \RuntimeException(sprintf('Unable to create upload directory: %s', $uploadDir));
        }

        $this->removeFileIfExists($question->getImageFileReference());

        $file->move($uploadDir, $filename);

        return $relativePath;
    }

    public function removeImageFile(?string $relativePath): void
    {
        $this->removeFileIfExists($relativePath);
    }

    public function buildFileResponse(?string $relativePath): BinaryFileResponse|Response
    {
        if ($relativePath === null) {
            return new Response('Image not found.', Response::HTTP_NOT_FOUND);
        }

        $filePath = $this->getAbsolutePath($relativePath);

        if ($filePath === null || !file_exists($filePath)) {
            return new Response('Image file not found.', Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', mime_content_type($filePath));
        $response->headers->set('Content-Disposition', 'inline; filename="' . basename($filePath) . '"');

        return $response;
    }

    public function getAbsolutePath(?string $relativePath): ?string
    {
        if ($relativePath === null) {
            return null;
        }

        return $this->getBaseDirectory() . '/' . $relativePath;
    }

    public function getPublicUrl(?string $relativePath): ?string
    {
        if ($relativePath === null) {
            return null;
        }

        return '/uploads/question-images/' . $relativePath;
    }

    public function copyImageFile(string $relativePath, Uuid $newPaperId, Uuid $newQuestionId): string
    {
        $sourcePath = $this->getBaseDirectory() . '/' . $relativePath;

        if (!file_exists($sourcePath)) {
            throw new \RuntimeException(sprintf('Source image file not found: %s', $relativePath));
        }

        $parts = explode('/', $relativePath);
        $filename = end($parts);
        $instituteId = $parts[0] ?? '';

        $newRelativePath = sprintf(
            '%s/%s/%s/%s',
            $instituteId,
            $newPaperId->toRfc4122(),
            $newQuestionId->toRfc4122(),
            $filename
        );

        $destDir = $this->getBaseDirectory() . '/' . dirname($newRelativePath);

        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            throw new \RuntimeException(sprintf('Unable to create directory for image copy: %s', $destDir));
        }

        if (!copy($sourcePath, $this->getBaseDirectory() . '/' . $newRelativePath)) {
            throw new \RuntimeException(sprintf('Failed to copy image file from %s to %s', $relativePath, $newRelativePath));
        }

        return $newRelativePath;
    }

    private function removeFileIfExists(?string $relativePath): void
    {
        if ($relativePath === null) {
            return;
        }

        $filePath = $this->getBaseDirectory() . '/' . $relativePath;

        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    private function getBaseDirectory(): string
    {
        return rtrim(
            $_ENV['QUESTION_IMAGE_UPLOAD_DIR'] ?? (
                $_SERVER['APP_ENV'] === 'test'
                    ? sys_get_temp_dir()
                    : $this->projectDir . '/public/uploads/question-images'
            ),
            '/'
        );
    }
}
