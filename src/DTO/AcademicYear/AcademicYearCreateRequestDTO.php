<?php
declare(strict_types=1);

namespace App\DTO\AcademicYear;

use Symfony\Component\Validator\Constraints as Assert;

readonly class AcademicYearCreateRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 50)]
        public string $name,

        #[Assert\NotBlank]
        #[Assert\Length(max: 50)]
        public string $code,

        #[Assert\NotBlank]
        public string $startDate,

        #[Assert\NotBlank]
        public string $endDate,

        public bool $isActive = false,

        #[Assert\Length(max: 500)]
        public ?string $description = null,
    ) {
    }
}
