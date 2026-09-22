<?php

declare(strict_types=1);

namespace App\DTO\Student;

use Symfony\Component\Validator\Constraints as Assert;

readonly class AcademicDetailsResponseDTO
{
    public function __construct(
        #[Assert\Length(max: 255)]
        public ?string $academicYear = null,
        #[Assert\Length(max: 255)]
        public ?string $academicClass = null,
        #[Assert\Length(max: 50)]
        public ?string $section = null,
    ) {
    }
}