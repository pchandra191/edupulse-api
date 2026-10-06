<?php
declare(strict_types=1);

namespace App\Service\Analysis\Processing;

use Psr\Log\LoggerInterface;

readonly class DocumentTextExtractor implements DocumentTextExtractorInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private string $projectDir,
    ) {
    }

    /**
     * @return array{
     *     text: string,
     *     pages: array<array{page: int, text: string}>,
     *     pageCount: int,
     *     hasExtractableText: bool,
     *     extractionMethod: string,
     * }
     */
    public function extractFromPdf(string $filePath): array
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            throw new \RuntimeException('PDF parser library not available');
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($filePath);
            $pages = $pdf->getPages();
            
            $extractedPages = [];
            $fullText = '';
            $fileSize = filesize($filePath);
            $fileId = basename($filePath);
            
            $this->logger->info('[PDF-EXTRACTION]', [
                'fileId' => $fileId,
                'path' => $filePath,
                'mimeType' => 'application/pdf',
                'fileSize' => $fileSize,
            ]);
            
            foreach ($pages as $index => $page) {
                $pageText = $page->getText();
                $pageNumber = $index + 1;
                
                $extractedPages[] = [
                    'page' => $pageNumber,
                    'text' => $pageText,
                ];
                
                $fullText .= "\n--- PAGE {$pageNumber} ---\n" . $pageText;
            }
            
            $extractedTextLength = strlen(trim($fullText));
            $pageCount = count($pages);
            $hasExtractableText = $extractedTextLength > 50;
            
            $this->logger->info('[PDF-EXTRACTION]', [
                'fileId' => $fileId,
                'path' => $filePath,
                'mimeType' => 'application/pdf',
                'fileSize' => $fileSize,
                'pageCount' => $pageCount,
                'extractedTextLength' => $extractedTextLength,
                'extractionStatus' => $extractedTextLength > 0 ? 'success' : 'empty',
            ]);
            
            $hasExtractableText = $extractedTextLength > 50;
            
            return [
                'text' => $fullText,
                'pages' => $extractedPages,
                'pageCount' => count($pages),
                'hasExtractableText' => $hasExtractableText,
                'extractionMethod' => 'pdf_text',
            ];
        } catch (\Throwable $e) {
            $this->logger->error('PDF text extraction failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            $this->logger->error('[PDF-EXTRACTION]', [
                'fileId' => basename($filePath),
                'path' => $filePath,
                'mimeType' => 'application/pdf',
                'fileSize' => filesize($filePath),
                'extractionStatus' => 'failed',
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to extract text from PDF: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array{
     *     text: string,
     *     pages: array<array{page: int, text: string}>,
     *     pageCount: int,
     *     hasExtractableText: bool,
     *     extractionMethod: string,
     * }
     */
    public function extractFromText(string $filePath): array
    {
        $this->logger->info('[TEXT-EXTRACTION]', [
            'fileId' => basename($filePath),
            'mimeType' => 'text/plain',
            'bytes' => filesize($filePath),
        ]);

        try {
            $text = file_get_contents($filePath);
            if ($text === false) {
                throw new \RuntimeException('Failed to read text file');
            }

            $textLength = strlen($text);
            
            $this->logger->info('[TEXT-EXTRACTION]', [
                'fileId' => basename($filePath),
                'mimeType' => 'text/plain',
                'bytes' => filesize($filePath),
                'textLength' => $textLength,
                'status' => $textLength > 0 ? 'success' : 'empty',
            ]);

            if ($textLength === 0) {
                $this->logger->warning('[TEXT-EXTRACTION]', [
                    'fileId' => basename($filePath),
                    'mimeType' => 'text/plain',
                    'bytes' => 0,
                    'textLength' => 0,
                    'status' => 'empty',
                ]);
            }

            return [
                'text' => $text,
                'pages' => [
                    [
                        'page' => 1,
                        'text' => $text,
                    ],
                ],
                'pageCount' => 1,
                'hasExtractableText' => $textLength > 0,
                'extractionMethod' => 'text_plain',
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Text file extraction failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to extract text from text file: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array{
     *     text: string,
     *     pages: array<array{page: int, text: string}>,
     *     pageCount: int,
     *     hasExtractableText: bool,
     *     extractionMethod: string,
     * }
     */
    public function extractFromImage(string $filePath, string $mimeType): array
    {
        // For now, return a placeholder for image extraction
        // OCR integration can be added here later
        $this->logger->warning('Image text extraction not yet implemented', [
            'file' => $filePath,
            'mime_type' => $mimeType,
        ]);
        
        return [
            'text' => '',
            'pages' => [
                [
                    'page' => 1,
                    'text' => '[Image content - OCR not implemented]',
                ],
            ],
            'pageCount' => 1,
            'hasExtractableText' => false,
            'extractionMethod' => 'ocr_not_implemented',
        ];
    }

    public function extract(string $filePath, string $mimeType): array
    {
        return match ($mimeType) {
            'application/pdf' => $this->extractFromPdf($filePath),
            'text/plain' => $this->extractFromText($filePath),
            'image/jpeg', 'image/png' => $this->extractFromImage($filePath, $mimeType),
            default => throw new \InvalidArgumentException('Unsupported file type for text extraction: ' . $mimeType),
        };
    }

    public function supportsMimeType(string $mimeType): bool
    {
        return in_array($mimeType, ['application/pdf', 'text/plain', 'image/jpeg', 'image/png'], true);
    }
}