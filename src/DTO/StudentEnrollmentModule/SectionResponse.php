<?php
declare(strict_types=1);

namespace App\DTO\StudentEnrollmentModule;

readonly class SectionResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
        public string $status,
    ) {
    }
}
