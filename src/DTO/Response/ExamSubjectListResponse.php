<?php
declare(strict_types=1);

namespace App\DTO\Response;

use App\DTO\ExaminationModule\ExamSubjectResponseDTO;

readonly class ExamSubjectListResponse
{
    /**
     * @param list<ExamSubjectResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public array $pagination,
    ) {
    }
}
