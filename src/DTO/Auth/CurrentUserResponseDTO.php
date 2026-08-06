<?php

namespace App\DTO\Auth;

readonly class CurrentUserResponseDTO
{
    public function __construct(
        public string $id,
        public string $email,
        public ?string $firstName,
        public ?string $lastName,
        public array $roles,
        public ?InstituteResponseDTO $institute = null,
    ) {
    }
}
