<?php
declare(strict_types=1);

namespace App\DTO\AcademicYear;

use Symfony\Component\Validator\Constraints as Assert;

readonly class AcademicYearUpdateRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 50)]
        public ?string $name = null,

        #[Assert\NotBlank]
        #[Assert\Length(max: 50)]
        public ?string $code = null,

        #[Assert\NotBlank]
        public ?string $startDate = null,

        #[Assert\NotBlank]
        public ?string $endDate = null,

        public ?bool $isActive = null,

        #[Assert\Length(max: 500)]
        public ?string $description = null,
    ) {
    }
}
