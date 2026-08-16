<?php
declare(strict_types=1);

namespace App\DTO\ExaminationModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UpdateExaminationRequestDTO
{
    public function __construct(
        #[Assert\Length(max: 100)]
        public ?string $name = null,

        #[Assert\Length(max: 30)]
        public ?string $code = null,

        #[Assert\Length(max: 1000)]
        public ?string $description = null,

        #[Assert\Date]
        public ?string $startDate = null,

        #[Assert\Date]
        public ?string $endDate = null,

        public ?string $status = null,
    ) {
    }
}
