<?php
declare(strict_types=1);

namespace App\DTO\Institute;

readonly class PublicInstituteResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
        public ?string $logo,
    ) {
    }
}