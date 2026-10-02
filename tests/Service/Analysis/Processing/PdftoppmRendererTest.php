<?php

declare(strict_types=1);

namespace App\Tests\Service\Analysis\Processing;

use App\Service\Analysis\Processing\PdfPageRendererInterface;
use App\Service\Analysis\Processing\PdftoppmRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class PdftoppmRendererTest extends TestCase
{
    public function testImplementsPdfPageRendererInterface(): void
    {
        $renderer = new PdftoppmRenderer('pdftoppm', new NullLogger());
        $this->assertInstanceOf(PdfPageRendererInterface::class, $renderer);
    }

    public function testIsAvailableReturnsFalseForEmptyBinaryPath(): void
    {
        $renderer = new PdftoppmRenderer('', new NullLogger());
        $this->assertFalse($renderer->isAvailable());
    }

    public function testIsAvailableReturnsFalseForNonExistentBinary(): void
    {
        $renderer = new PdftoppmRenderer('/nonexistent/binary/pdftoppm', new NullLogger());
        $this->assertFalse($renderer->isAvailable());
    }

    public function testIsAvailableReturnsTrueForExistingBinary(): void
    {
        $binaryPath = $this->findRealBinary();
        if ($binaryPath === null) {
            $this->markTestSkipped('pdftoppm binary not available');
        }

        $renderer = new PdftoppmRenderer($binaryPath, new NullLogger());
        $this->assertTrue($renderer->isAvailable());
    }

    private function findRealBinary(): ?string
    {
        $paths = [
            $_ENV['PDF_RENDERER_BINARY'] ?? '',
            getenv('PDF_RENDERER_BINARY') ?: '',
            'C:\\poppler\\poppler-24.02.0\\Library\\bin\\pdftoppm.exe',
            'C:\\poppler\\poppler-24.08.0\\Library\\bin\\pdftoppm.exe',
        ];

        foreach ($paths as $path) {
            if ($path !== '' && file_exists($path)) {
                return $path;
            }
        }

        $output = [];
        $result = 0;
        @exec('where pdftoppm 2>nul', $output, $result);
        if ($result === 0 && !empty($output)) {
            return $output[0];
        }

        @exec('which pdftoppm 2>/dev/null', $output, $result);
        if ($result === 0 && !empty($output)) {
            return $output[0];
        }

        return null;
    }

    public function testGetPageCountThrowsForNonExistentPdf(): void
    {
        $renderer = new PdftoppmRenderer('pdftoppm', new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to determine PDF page count');

        $renderer->getPageCount('/nonexistent/file.pdf');
    }

    public function testRenderPageFailsForNonExistentPdf(): void
    {
        $renderer = new PdftoppmRenderer('/nonexistent/pdftoppm', new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pdftoppm failed');

        $renderer->renderPage('/nonexistent/file.pdf', 1);
    }

    public function testGetPdfInfoPathReturnsPdfinfoForSimpleBinaryName(): void
    {
        $renderer = new PdftoppmRenderer('pdftoppm', new NullLogger());

        $reflection = new \ReflectionClass($renderer);
        $method = $reflection->getMethod('getPdfInfoPath');
        $method->setAccessible(true);

        $result = $method->invoke($renderer);
        $this->assertSame('pdfinfo', $result);
    }

    public function testGetPdfInfoPathReturnsNullWhenPdfinfoNotPresent(): void
    {
        $dir = sys_get_temp_dir() . '/pdftoppm_test_dir_' . uniqid();
        mkdir($dir);
        $binaryPath = $dir . '/pdftoppm';
        file_put_contents($binaryPath, '');

        $renderer = new PdftoppmRenderer($binaryPath, new NullLogger());

        $reflection = new \ReflectionClass($renderer);
        $method = $reflection->getMethod('getPdfInfoPath');
        $method->setAccessible(true);

        $result = $method->invoke($renderer);
        $this->assertNull($result);

        unlink($binaryPath);
        rmdir($dir);
    }

    public function testGetPdfInfoPathDerivesFromBinaryDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/pdftoppm_test_dir_' . uniqid();
        mkdir($dir);
        $pdftoppmPath = $dir . '/pdftoppm';
        $pdfinfoPath = $dir . '/pdfinfo';
        file_put_contents($pdftoppmPath, '');
        file_put_contents($pdfinfoPath, '');

        $renderer = new PdftoppmRenderer($pdftoppmPath, new NullLogger());

        $reflection = new \ReflectionClass($renderer);
        $method = $reflection->getMethod('getPdfInfoPath');
        $method->setAccessible(true);

        $result = $method->invoke($renderer);
        $this->assertSame($pdfinfoPath, $result);

        unlink($pdftoppmPath);
        unlink($pdfinfoPath);
        rmdir($dir);
    }

    public function testGetPdfInfoPathReturnsPdfinfoExeOnWindows(): void
    {
        $dir = sys_get_temp_dir() . '/pdftoppm_test_dir_' . uniqid();
        mkdir($dir);
        $pdftoppmPath = $dir . '/pdftoppm.exe';
        $pdfinfoPath = $dir . '/pdfinfo.exe';
        file_put_contents($pdftoppmPath, '');
        file_put_contents($pdfinfoPath, '');

        $renderer = new PdftoppmRenderer($pdftoppmPath, new NullLogger());

        $reflection = new \ReflectionClass($renderer);
        $method = $reflection->getMethod('getPdfInfoPath');
        $method->setAccessible(true);

        $result = $method->invoke($renderer);
        $this->assertSame($pdfinfoPath, $result);

        unlink($pdftoppmPath);
        unlink($pdfinfoPath);
        rmdir($dir);
    }

    public function testRenderRealPdfPageWithPdftoppm(): void
    {
        $binaryPath = $_ENV['PDF_RENDERER_BINARY'] ?? '';

        if ($binaryPath === '') {
            $output = [];
            $result = 0;
            @exec('where pdftoppm 2>nul', $output, $result);
            if ($result === 0 && !empty($output)) {
                $binaryPath = $output[0];
            } else {
                $output2 = [];
                $result2 = 0;
                @exec('which pdftoppm 2>/dev/null', $output2, $result2);
                if ($result2 === 0 && !empty($output2)) {
                    $binaryPath = $output2[0];
                }
            }
        }

        $popplerDirs = [
            'C:\\poppler',
            'C:\\poppler\\poppler-24.02.0\\Library\\bin',
            '/usr/bin',
            '/usr/local/bin',
        ];
        if ($binaryPath === '') {
            foreach ($popplerDirs as $dir) {
                foreach (['pdftoppm', 'pdftoppm.exe'] as $name) {
                    $candidate = $dir . DIRECTORY_SEPARATOR . $name;
                    if (file_exists($candidate)) {
                        $binaryPath = $candidate;
                        break 2;
                    }
                }
            }
        }

        if ($binaryPath === '' || !file_exists($binaryPath)) {
            $this->markTestSkipped('pdftoppm binary not available in this environment');
        }

        $projectDir = (string) ($_ENV['PROJECT_DIR'] ?? dirname(__DIR__, 4));
        $fixturePath = $projectDir . '/tests/fixtures/analysis/answer-sheet.pdf';
        if (!file_exists($fixturePath)) {
            $this->markTestSkipped('Test fixture PDF not found');
        }

        $renderer = new PdftoppmRenderer($binaryPath, new NullLogger());

        $this->assertTrue($renderer->isAvailable(), 'Renderer should be available with real binary');

        $pageCount = $renderer->getPageCount($fixturePath);
        $this->assertGreaterThan(0, $pageCount, 'Should detect at least one page');

        $imagePath = $renderer->renderPage($fixturePath, 1, 150);
        $this->assertFileExists($imagePath, 'Rendered image file should exist');
        $this->assertGreaterThan(0, filesize($imagePath), 'Rendered image should not be empty');

        // Cleanup
        if (file_exists($imagePath)) {
            unlink($imagePath);
        }
        $tempDir = dirname($imagePath);
        if (is_dir($tempDir) && strpos($tempDir, 'edupulse_pdf_render_') !== false) {
            $this->removeTestDirectory($tempDir);
        }
    }

    public function testGetDiagnosticsWithUnavailableRenderer(): void
    {
        $renderer = new PdftoppmRenderer('', new NullLogger());
        $diagnostics = $renderer->getDiagnostics();

        $this->assertFalse($diagnostics['is_available']);
        $this->assertTrue($diagnostics['binary_path_empty']);
    }

    public function testGetDiagnosticsWithRealRenderer(): void
    {
        $binaryPath = $this->findRealBinary();
        if ($binaryPath === null) {
            $this->markTestSkipped('pdftoppm binary not available');
        }

        $renderer = new PdftoppmRenderer($binaryPath, new NullLogger());
        $diagnostics = $renderer->getDiagnostics();

        $this->assertTrue($diagnostics['is_available']);
        $this->assertTrue($diagnostics['pdftoppm_file_exists']);
        $this->assertTrue($diagnostics['pdftoppm_executable']);
    }

    private function removeTestDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeTestDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
