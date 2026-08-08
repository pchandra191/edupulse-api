<?php
declare(strict_types=1);

namespace App\DTO\StudentEnrollmentModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateEnrollmentSectionRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $sectionId,

        #[Assert\NotBlank]
        public string $status = 'ACTIVE',
    ) {
    }
}
