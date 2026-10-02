<?php

declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class AnalysisListResponseDTO
{
    /** @param array<AnalysisSummaryDTO> $items */
    public function __construct(
        public array $items,
        public PaginationDTO $pagination,
    ) {
    }
}