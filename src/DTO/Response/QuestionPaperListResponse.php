<?php
declare(strict_types=1);

namespace App\DTO\Response;

use App\DTO\QuestionPaperModule\QuestionPaperResponseDTO;

readonly class QuestionPaperListResponse
{
    /**
     * @param list<QuestionPaperResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public array $pagination,
    ) {
    }
}
