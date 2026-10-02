<?php

declare(strict_types=1);

namespace App\Service\Analysis\Processing;

interface DocumentTextExtractorInterface
{
    /**
     * @return array{
     *     text: string,
     *     pages: array<array{page: int, text: string}>,
     *     pageCount: int,
     *     hasExtractableText: bool,
     *     extractionMethod: string,
     * }
     */
    public function extract(string $filePath, string $mimeType): array;
    
    public function supportsMimeType(string $mimeType): bool;
}