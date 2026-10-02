<?php

declare(strict_types=1);

namespace App\Service\Analysis\Processing;

/**
 * Interface for rendering PDF pages to images.
 */
interface PdfPageRendererInterface
{
    /**
     * Render a specific PDF page to an image file.
     *
     * @param string $pdfPath Path to the PDF file
     * @param int $pageNumber Page number to render (1-based)
     * @param int $dpi Resolution in DPI (default 300)
     * @param string $format Output format: 'png' (default) or 'jpeg'
     * @return string Path to the rendered image file
     */
    public function renderPage(string $pdfPath, int $pageNumber, int $dpi = 300, string $format = 'png'): string;

    /**
     * Get the total number of pages in a PDF.
     *
     * @param string $pdfPath Path to the PDF file
     * @return int Number of pages
     */
    public function getPageCount(string $pdfPath): int;

    /**
     * Check if the renderer is available on the system.
     */
    public function isAvailable(): bool;
}