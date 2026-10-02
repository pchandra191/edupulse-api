<?php

declare(strict_types=1);

namespace App\Service\Analysis;

readonly class AnalysisStorageResolver
{
    public function __construct(
        private string $projectDir,
        private string $uploadDirOverride = '',
    ) {
    }

    public function getBaseUploadDir(): string
    {
        if ($this->uploadDirOverride !== '') {
            return rtrim($this->uploadDirOverride, '/');
        }

        // Use projectDir directly instead of env var (which contains unresolved %kernel.project_dir%)
        return rtrim($this->projectDir . '/var/uploads/analysis_documents', '/');
    }

    public function getAnalysisDirectory(string $analysisId): string
    {
        return $this->getBaseUploadDir() . '/' . $analysisId;
    }

    public function getFilePath(string $analysisId, string $fileId, string $extension): string
    {
        $analysisDir = $this->getAnalysisDirectory('');
        $analysisDir = dirname($analysisDir) . '/' . $analysisId;
        
        $filename = $fileId . '.' . $extension;
        $filePath = $analysisDir . '/' . $filename;

        if (!file_exists($filePath)) {
            throw new \RuntimeException('Document file not found: ' . $fileId . '.' . $extension);
        }

        return $filePath;
    }

    public function getFilePathByFileId(string $analysisId, string $fileId): string
    {
        $analysisDir = $this->getAnalysisDirectory($analysisId);
        
        if (!is_dir($analysisDir)) {
            throw new \RuntimeException('Analysis document directory not found: ' . $analysisDir);
        }

        // Find the file with matching fileId prefix (format: {fileId}.{extension})
        $files = glob($analysisDir . '/' . $fileId . '.*');
        if (empty($files)) {
            throw new \RuntimeException('Document file not found: ' . $fileId);
        }

        // Verify the file is within the expected directory (prevent path traversal)
        $realAnalysisDir = realpath($analysisDir);
        $realFile = realpath($files[0]);
        if ($realFile === false || strpos($realFile, $realAnalysisDir) !== 0) {
            throw new \RuntimeException('Invalid file path');
        }

        return $files[0];
    }

    public function ensureAnalysisDirectory(string $analysisId): string
    {
        $analysisDir = $this->getAnalysisDirectory($analysisId);
        if (!is_dir($analysisDir) && !mkdir($analysisDir, 0755, true) && !is_dir($analysisDir)) {
            throw new \RuntimeException(sprintf('Unable to create upload directory: %s', $analysisDir));
        }
        return $analysisDir;
    }
}