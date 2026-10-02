<?php

declare(strict_types=1);

namespace App\Service\Analysis\Processing;

interface VisionOCRServiceInterface
{
    /**
     * Process a single image file through the Vision/OCR API.
     *
     * @param string $filePath Path to the image file (PNG/JPNG/JPEG/WebP)
     * @param int $pageNumber Page number for metadata
     * @param string|null $fileHash Optional file hash for cache key (computed from file if null)
     * @return array{
     *     pages: array<array{
     *         pageNumber: int,
     *         text: string,
     *         questions: array<array{
     *             questionNumber: string,
     *             questionText: string,
     *             answerText: string,
     *             marks: int|null
     *         }>,
     *         metadata: array{
     *             studentName: string|null,
     *             rollNumber: string|null,
     *             subject: string|null,
     *             examTitle: string|null,
     *             class: string|null,
     *             section: string|null,
     *             totalMarks: int|null
     *         },
     *         confidence: float,
     *         fromCache: bool,
     *     }>,
     *     metadata: array{
     *         studentName: string|null,
     *         rollNumber: string|null,
     *         subject: string|null,
     *         examTitle: string|null,
     *         class: string|null,
     *         section: string|null,
     *         totalMarks: int|null
     *     },
     *     confidence: float,
     *     extractionMethod: string,
     *     ocrMetrics: array{
     *         pagesDetected: int,
     *         pagesProcessed: int,
     *         pagesSkipped: int,
     *         ocrPagesProcessed: int,
     *         ocrCacheHits: int,
     *         ocrAttempts: int,
     *         ocrRetryCount: int,
     *     },
     *     language: string,
     * }
     */
    public function processPageImage(string $filePath, int $pageNumber, ?string $fileHash = null): array;

    /**
     * Process a PDF file by rendering pages to images and OCR each page.
     * Uses cache to avoid duplicate OCR calls.
     *
     * @param array<int>|null $pagesToProcess Specific pages to process (null = all)
     * @return array{
     *     pages: array<array{
     *         pageNumber: int,
     *         text: string,
     *         questions: array<array{
     *             questionNumber: string,
     *             questionText: string,
     *             answerText: string,
     *             marks: int|null
     *         }>,
     *         metadata: array{
     *             studentName: string|null,
     *             rollNumber: string|null,
     *             subject: string|null,
     *             examTitle: string|null,
     *             class: string|null,
     *             section: string|null,
     *             totalMarks: int|null
     *         },
     *         confidence: float,
     *         fromCache: bool,
     *     }>,
     *     metadata: array{
     *         studentName: string|null,
     *         rollNumber: string|null,
     *         subject: string|null,
     *         examTitle: string|null,
     *         class: string|null,
     *         section: string|null,
     *         totalMarks: int|null
     *     },
     *     confidence: float,
     *     extractionMethod: string,
     *     ocrMetrics: array{
     *         pagesDetected: int,
     *         pagesProcessed: int,
     *         pagesSkipped: int,
     *         ocrPagesProcessed: int,
     *         ocrCacheHits: int,
     *         ocrAttempts: int,
     *         ocrRetryCount: int,
     *     }
     * }
     */
    public function processPdf(string $filePath, string $fileHash, ?array $pagesToProcess = null): array;

    public function supportsMimeType(string $mimeType): bool;
}
