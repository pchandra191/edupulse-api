<?php

namespace App\DTO\Auth;

readonly class EnrollmentResponseDTO
{
    public function __construct(
        public string $id,
        public string $status,
        public ?AcademicClassReferenceDTO $academicClass = null,
    ) {
    }
}
