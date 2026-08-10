<?php
declare(strict_types=1);

namespace App\DTO\ClassModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateClassRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $academicYearId,

        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $name,

        #[Assert\NotBlank]
        #[Assert\Length(max: 30)]
        public string $code,

        #[Assert\Length(max: 1000)]
        public ?string $description = null,

        #[Assert\NotBlank]
        public string $status = 'ACTIVE',

        #[Assert\Length(max: 36)]
        public ?string $instituteId = null,
    ) {
    }
}
