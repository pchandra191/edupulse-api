<?php
declare(strict_types=1);

namespace App\DTO\SectionModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateSectionRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $academicClassId,

        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $name,

        #[Assert\NotBlank]
        #[Assert\Length(max: 20)]
        public string $code,

        #[Assert\Positive]
        public ?int $capacity = null,

        #[Assert\Length(max: 1000)]
        public ?string $description = null,

        #[Assert\NotBlank]
        public string $status = 'ACTIVE',
    ) {
    }
}
