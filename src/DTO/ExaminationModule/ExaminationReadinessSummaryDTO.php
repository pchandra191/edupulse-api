<?php
declare(strict_types=1);

namespace App\DTO\ExaminationModule;

readonly class ExaminationReadinessSummaryDTO
{
    public function __construct(
        public bool $ready,
        public int $totalSubjects,
        public int $readySubjects,
        public int $notReadySubjects,
        public int $sessionsCreated,
        public bool $canValidateAndStart,
        public array $subjects,
        public int $activeSubjects,
        public int $cancelledSubjects,
        public int $validatedSubjects,
    ) {
    }
}
