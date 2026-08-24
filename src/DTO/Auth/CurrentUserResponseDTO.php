<?php

namespace App\DTO\Auth;

use App\DTO\Auth\EnrollmentResponseDTO;
use App\DTO\Auth\InstituteResponseDTO;

readonly class CurrentUserResponseDTO
{
    /**
     * @param list<EnrollmentResponseDTO> $enrollments
     */
    public function __construct(
        public string $id,
        public string $email,
        public ?string $firstName,
        public ?string $lastName,
        public array $roles,
        public ?InstituteResponseDTO $institute = null,
        public array $enrollments = [],
    ) {
    }
}
