<?php
declare(strict_types=1);

namespace App\DTO\StudentEnrollmentModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UpdateEnrollmentSectionRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $status,
    ) {
    }
}
