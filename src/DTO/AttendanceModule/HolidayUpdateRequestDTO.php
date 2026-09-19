<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class HolidayUpdateRequestDTO
{
    public function __construct(
        #[Assert\Date]
        public ?string $date = null,

        #[Assert\Length(max: 150)]
        public ?string $name = null,

        #[Assert\Length(max: 1000)]
        public ?string $description = null,

        public ?bool $enabled = null,

        #[Assert\Length(max: 36)]
        public ?string $academicYearId = null,

        #[Assert\Length(max: 36)]
        public ?string $academicClassId = null,

        #[Assert\Length(max: 36)]
        public ?string $sectionId = null,
    ) {
    }
}
