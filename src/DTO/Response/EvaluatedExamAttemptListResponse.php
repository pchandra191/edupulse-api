<?php
declare(strict_types=1);

namespace App\DTO\Response;

readonly class EvaluatedExamAttemptListResponse
{
    /**
     * @param array<App\DTO\ExamAttemptModule\EvaluatedExamAttemptResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public array $pagination,
    ) {
    }
}
