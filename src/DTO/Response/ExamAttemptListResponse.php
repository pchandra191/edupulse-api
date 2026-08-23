<?php
declare(strict_types=1);

namespace App\DTO\Response;

readonly class ExamAttemptListResponse
{
    /**
     * @param array<ExamAttemptResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public array $pagination,
    ) {
    }
}
