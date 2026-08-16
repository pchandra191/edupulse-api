<?php
declare(strict_types=1);

namespace App\DTO\ExaminationModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateExaminationRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $academicYearId,

        #[Assert\NotBlank]
        public string $academicClassId,

        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $name,

        #[Assert\NotBlank]
        #[Assert\Length(max: 30)]
        public string $code,

        #[Assert\NotBlank]
        #[Assert\Date]
        public string $startDate,

        #[Assert\NotBlank]
        #[Assert\Date]
        public string $endDate,

        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['DRAFT', 'SCHEDULED', 'ONGOING', 'COMPLETED', 'CANCELLED', 'ARCHIVED'], message: 'Invalid status. Allowed values: DRAFT, SCHEDULED, ONGOING, COMPLETED, CANCELLED, ARCHIVED.')]
        public string $status = 'DRAFT',

        #[Assert\Length(max: 1000)]
        public ?string $description = null,

        #[Assert\Length(max: 36)]
        public ?string $instituteId = null,
    ) {
    }
}
