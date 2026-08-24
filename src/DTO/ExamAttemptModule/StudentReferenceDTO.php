<?php
declare(strict_types=1);

namespace App\DTO\ExamAttemptModule;

readonly class StudentReferenceDTO
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
