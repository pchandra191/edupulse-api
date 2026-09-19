<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class HolidayFilterRequest
{
    public function __construct(
        #[Assert\Length(max: 36)]
        public ?string $academicYearId = null,

        public ?string $date = null,

        #[Assert\Choice(choices: ['true', 'false', '1', '0'])]
        public ?string $enabled = null,

        public int $page = 1,

        public int $limit = 10,

        #[Assert\Length(max: 50)]
        public string $sortBy = 'holidayDate',

        #[Assert\Length(max: 4)]
        public string $sortOrder = 'ASC',
    ) {
    }
}
