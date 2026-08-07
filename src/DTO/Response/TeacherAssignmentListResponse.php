<?php
declare(strict_types=1);

namespace App\DTO\Response;

use App\DTO\TeacherAssignmentModule\TeacherAssignmentResponseDTO;

readonly class TeacherAssignmentListResponse
{
    /**
     * @param list<TeacherAssignmentResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public array $pagination,
    ) {
    }
}
