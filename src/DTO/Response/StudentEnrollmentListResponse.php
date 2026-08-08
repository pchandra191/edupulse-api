<?php
declare(strict_types=1);

namespace App\DTO\Response;

use App\DTO\StudentEnrollmentModule\StudentEnrollmentResponseDTO;

readonly class StudentEnrollmentListResponse
{
    /**
     * @param list<StudentEnrollmentResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public array $pagination,
    ) {
    }
}
