<?php
declare(strict_types=1);

namespace App\DTO\QuestionPaperModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class QuestionPaperFilterRequest
{
    public function __construct(
        public ?string $search = null,
        public ?string $academicYearId = null,
        public ?string $subjectId = null,
        public ?string $examinationId = null,
        public ?string $status = null,
        public int $page = 1,
        public int $limit = 10,
        public string $sortBy = 'createdAt',
        public string $sortOrder = 'DESC',
    ) {
    }
}
