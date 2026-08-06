<?php
declare(strict_types=1);

namespace App\DTO\Auth;

readonly class InstituteResponseDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
        public ?string $logo,
        public string $status,
    ) {
    }
}
