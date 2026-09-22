<?php
declare(strict_types=1);

namespace App\DTO\Auth;

readonly class InstituteRegistrationInfoResponseDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $logo,
    ) {
    }
}
