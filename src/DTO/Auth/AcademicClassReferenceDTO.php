<?php

namespace App\DTO\Auth;

readonly class AcademicClassReferenceDTO
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
