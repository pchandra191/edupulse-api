<?php

declare(strict_types=1);

namespace App\Service\Analysis\Processing;

use Mpdf\Mpdf;
use Psr\Log\LoggerInterface;

class ImageToPdfConverter
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Convert an image file to PDF
     * Returns path to the generated PDF file
     */
    public function convert(string $imagePath): string
    {
        $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        $mimeType = mime_content_type($imagePath);
        
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            // Not an image we need to convert
            return $imagePath;
        }

        $pdfPath = $imagePath . '.pdf';
        
        // If PDF already exists and is newer than source, reuse it
        if (file_exists($pdfPath) && filemtime($pdfPath) >= filemtime($imagePath)) {
            return $pdfPath;
        }

        try {
            // Get image dimensions
            $imageInfo = getimagesize($imagePath);
            if ($imageInfo === false) {
                throw new \RuntimeException('Failed to get image dimensions');
            }

            [$width, $height, $type] = $imageInfo;
            
            // Create mPDF with page size matching image (in mm, 1px = 0.264583mm at 96 DPI)
            $pageWidthMm = $width * 0.264583;
            $pageHeightMm = $height * 0.264583;
            
            $mpdf = new Mpdf([
                'format' => [$pageWidthMm, $pageHeightMm],
                'margin_left' => 0,
                'margin_right' => 0,
                'margin_top' => 0,
                'margin_bottom' => 0,
                'margin_header' => 0,
                'margin_footer' => 0,
            ]);

            // Add the image as full page
            $html = sprintf(
                '<img src="%s" width="%d" height="%d" style="margin:0;padding:0;border:none;">',
                $imagePath,
                $width,
                $height
            );

            $mpdf->WriteHTML($html);
            $mpdf->Output($pdfPath, 'F');

            $this->logger->info('[IMAGE-TO-PDF]', [
                'source' => $imagePath,
                'destination' => $pdfPath,
                'mimeType' => $mimeType,
                'dimensions' => "{$width}x{$height}",
            ]);

            return $pdfPath;
        } catch (\Throwable $e) {
            $this->logger->error('Image to PDF conversion failed', [
                'source' => $imagePath,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to convert image to PDF: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Convert multiple images to PDFs
     * Returns array of PDF paths (same order as input)
     */
    public function convertMultiple(array $imagePaths): array
    {
        $pdfPaths = [];
        foreach ($imagePaths as $path) {
            $pdfPaths[] = $this->convert($path);
        }
        return $pdfPaths;
    }
}