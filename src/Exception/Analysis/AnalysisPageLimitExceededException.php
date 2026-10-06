<?php

declare(strict_types=1);

namespace App\Exception\Analysis;

class AnalysisPageLimitExceededException extends \InvalidArgumentException
{
    private array $errorData;

    public function __construct(
        string $message,
        int $maxPages,
        int $existingPages,
        int $requestedPages,
        int $totalPages
    ) {
        parent::__construct($message, 0, null);
        $this->errorData = [
            'code' => 'ANALYSIS_PAGE_LIMIT_EXCEEDED',
            'maxPages' => $maxPages,
            'existingPages' => $existingPages,
            'requestedPages' => $requestedPages,
            'totalPages' => $totalPages,
        ];
    }

    public function getErrorData(): array
    {
        return $this->errorData;
    }
}