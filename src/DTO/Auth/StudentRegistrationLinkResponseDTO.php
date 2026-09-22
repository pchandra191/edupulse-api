<?php
declare(strict_types=1);

namespace App\DTO\Auth;

readonly class StudentRegistrationLinkResponseDTO
{
    public function __construct(
        public string $registrationUrl,
    ) {
    }
}
