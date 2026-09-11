<?php
declare(strict_types=1);

namespace App\DTO\ExaminationModule;

readonly class ExamSessionSummary
{
    public function __construct(
        public string $id,
        public string $status,
        public ?string $availableFrom,
        public ?string $availableTo,
    ) {
    }
}
