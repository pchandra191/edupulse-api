<?php
declare(strict_types=1);

namespace App\DTO\SectionModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UpdateSectionRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public ?string $name = null,

        #[Assert\NotBlank]
        #[Assert\Length(max: 20)]
        public ?string $code = null,

        #[Assert\Positive]
        public ?int $capacity = null,

        #[Assert\Length(max: 1000)]
        public ?string $description = null,

        #[Assert\NotBlank]
        public ?string $status = null,
    ) {
    }
}
