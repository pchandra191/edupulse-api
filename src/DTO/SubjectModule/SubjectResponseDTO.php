<?php
declare(strict_types=1);

namespace App\DTO\SubjectModule;

readonly class SubjectResponseDTO
{
    public function __construct(
        public string $id,
        public string $instituteId,
        public string $name,
        public string $code,
        public ?string $description,
        public string $status,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
