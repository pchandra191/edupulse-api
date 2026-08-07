<?php
declare(strict_types=1);

namespace App\DTO\Response;

use App\DTO\SubjectModule\SubjectResponseDTO;

readonly class ClassSubjectListResponse
{
    /**
     * @param list<SubjectResponseDTO> $items
     */
    public function __construct(
        public array $items,
        public array $pagination,
    ) {
    }
}
