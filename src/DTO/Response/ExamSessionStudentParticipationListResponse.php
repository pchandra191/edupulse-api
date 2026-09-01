<?php
declare(strict_types=1);

namespace App\DTO\Response;

use App\DTO\ExamSessionModule\ExamSessionStudentParticipationResponseDTO;
use App\DTO\ExamSessionModule\StudentParticipationSummaryDTO;

readonly class ExamSessionStudentParticipationListResponse
{
    /**
     * @param list<ExamSessionStudentParticipationResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public StudentParticipationSummaryDTO $summary,
        public array $pagination,
    ) {
    }

    public function toArray(): array
    {
        return [
            'items' => array_map(
                fn (ExamSessionStudentParticipationResponseDTO $item) => $item->toArray(),
                $this->items
            ),
            'summary' => $this->summary->toArray(),
            'pagination' => $this->pagination,
        ];
    }
}
