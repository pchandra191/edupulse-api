<?php

declare(strict_types=1);

namespace App\Service\Analysis\Processing;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

readonly class PdftoppmRenderer implements PdfPageRendererInterface
{
    public function __construct(
        private string $binaryPath,
        private LoggerInterface $logger,
    ) {
    }

    public function renderPage(string $pdfPath, int $pageNumber, int $dpi = 300, string $format = 'png'): string
    {
        $tempDir = sys_get_temp_dir() . '/edupulse_pdf_render_' . uniqid('', true);
        mkdir($tempDir, 0700, true);

        $outputPrefix = $tempDir . '/page';
        $formatFlag = $format === 'jpeg' ? '-jpeg' : '-png';
        $command = sprintf(
            '"%s" %s -r %d -f %d -l %d "%s" "%s"',
            $this->binaryPath,
            $formatFlag,
            $dpi,
            $pageNumber,
            $pageNumber,
            $pdfPath,
            $outputPrefix
        );

        $this->logger->debug('Rendering PDF page with pdftoppm', [
            'pdf' => $pdfPath,
            'page' => $pageNumber,
            'dpi' => $dpi,
            'format' => $format,
            'command' => $command,
        ]);

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->removeDirectory($tempDir);
            throw new \RuntimeException(
                'pdftoppm failed: ' . $process->getErrorOutput()
            );
        }

        $extension = $format === 'jpeg' ? 'jpg' : 'png';
        $expectedFile = $tempDir . '/page-1.' . $extension;
        if (!file_exists($expectedFile)) {
            $files = glob($tempDir . '/page-*.' . $extension);
            if (empty($files)) {
                $this->removeDirectory($tempDir);
                throw new \RuntimeException('pdftoppm did not produce any output files');
            }
            $expectedFile = $files[0];
        }

        return $expectedFile;
    }

    public function getPageCount(string $pdfPath): int
    {
        $pdfInfoPath = $this->getPdfInfoPath();

        if ($pdfInfoPath !== null) {
            $command = sprintf('"%s" -f 1 "%s" 2>&1', $pdfInfoPath, $pdfPath);
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(30);
            $process->run();

            if ($process->isSuccessful()) {
                $output = $process->getOutput();
                if (preg_match('/^\s*Pages:\s*(\d+)/im', $output, $matches)) {
                    return (int) $matches[1];
                }
            }

            $this->logger->warning('pdfinfo failed for page count, falling back to rendering', [
                'pdf' => $pdfPath,
                'pdfinfo_path' => $pdfInfoPath,
                'error' => $process->getErrorOutput(),
            ]);
        }

        $pageCount = $this->countPagesByRendering($pdfPath);
        if ($pageCount > 0) {
            return $pageCount;
        }

        throw new \RuntimeException('Unable to determine PDF page count');
    }

    public function isAvailable(): bool
    {
        if ($this->binaryPath === '' || $this->binaryPath === '0') {
            $this->logger->warning('PDF renderer binary path is empty or zero, PDF rendering not configured');
            return false;
        }

        $binaryExists = file_exists($this->binaryPath);

        if (!$binaryExists) {
            $paths = explode(PATH_SEPARATOR, getenv('PATH') ?: '');
            foreach ($paths as $dir) {
                if ($dir === '') {
                    continue;
                }
                $candidate = $dir . DIRECTORY_SEPARATOR . $this->binaryPath;
                if (file_exists($candidate)) {
                    $binaryExists = true;
                    $this->logger->debug('PDF renderer binary found via PATH resolution', [
                        'configured_path' => $this->binaryPath,
                        'resolved_path' => $candidate,
                    ]);
                    break;
                }
            }
        }

        if (!$binaryExists) {
            $this->logger->warning('PDF renderer binary not found', [
                'binary_path' => $this->binaryPath,
                'binary_exists' => false,
            ]);
            return false;
        }

        $process = new Process([$this->binaryPath, '-v']);
        $process->setTimeout(5);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->warning('PDF renderer binary exists but is not executable', [
                'binary_path' => $this->binaryPath,
                'exit_code' => $process->getExitCode(),
                'error_output' => $process->getErrorOutput(),
            ]);
            return false;
        }

        $this->logger->debug('PDF renderer is available', [
            'binary_path' => $this->binaryPath,
            'exit_code' => $process->getExitCode(),
        ]);

        return true;
    }

    public function getDiagnostics(): array
    {
        $binaryPath = $this->binaryPath;
        $binaryExists = file_exists($binaryPath);
        $pdfInfoPath = $this->getPdfInfoPath();
        $pdfInfoExists = $pdfInfoPath !== null ? file_exists($pdfInfoPath) : false;

        $diagnostics = [
            'configured_binary_path' => $binaryPath,
            'binary_path_empty' => $binaryPath === '' || $binaryPath === '0',
            'pdftoppm_file_exists' => $binaryExists,
            'pdftoppm_executable' => false,
            'pdfinfo_path' => $pdfInfoPath,
            'pdfinfo_file_exists' => $pdfInfoExists,
            'pdfinfo_executable' => false,
            'is_available' => false,
        ];

        if ($binaryExists) {
            $process = new Process([$binaryPath, '-v']);
            $process->setTimeout(5);
            $process->run();
            $diagnostics['pdftoppm_executable'] = $process->isSuccessful();
            $diagnostics['pdftoppm_exit_code'] = $process->getExitCode();
            $diagnostics['pdftoppm_output'] = trim($process->getOutput() . $process->getErrorOutput());
        }

        if ($pdfInfoExists) {
            $process = new Process([$pdfInfoPath, '-v']);
            $process->setTimeout(5);
            $process->run();
            $diagnostics['pdfinfo_executable'] = $process->isSuccessful();
            $diagnostics['pdfinfo_exit_code'] = $process->getExitCode();
        }

        $diagnostics['is_available'] = $this->isAvailable();

        return $diagnostics;
    }

    private function getPdfInfoPath(): ?string
    {
        $dir = dirname($this->binaryPath);
        if ($dir === '.') {
            return 'pdfinfo';
        }

        $candidate = $dir . '/pdfinfo';
        if (file_exists($candidate)) {
            return $candidate;
        }

        $windowsCandidate = $dir . '/pdfinfo.exe';
        if (file_exists($windowsCandidate)) {
            return $windowsCandidate;
        }

        return null;
    }

    private function countPagesByRendering(string $pdfPath): int
    {
        $tempDir = sys_get_temp_dir() . '/edupulse_pdf_count_' . uniqid('', true);
        mkdir($tempDir, 0700, true);

        $outputPrefix = $tempDir . '/page';
        $command = sprintf(
            '"%s" -png -r 72 "%s" "%s"',
            $this->binaryPath,
            $pdfPath,
            $outputPrefix
        );

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(120);
        $process->run();

        $pageCount = 0;
        if ($process->isSuccessful()) {
            $files = glob($tempDir . '/page-*.png');
            $pageCount = count($files);
        }

        $this->removeDirectory($tempDir);

        return $pageCount;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
