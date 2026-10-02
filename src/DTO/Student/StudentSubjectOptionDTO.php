<?php
declare(strict_types=1);

namespace App\DTO\Student;

readonly class StudentSubjectOptionDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public string $source,
    ) {
    }
}
