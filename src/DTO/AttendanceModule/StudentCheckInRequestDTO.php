<?php
declare(strict_types=1);

namespace App\DTO\AttendanceModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class StudentCheckInRequestDTO
{
    public function __construct(
        #[Assert\Range(min: -90, max: 90, notInRangeMessage: 'Latitude must be between -90 and 90.')]
        public float $latitude,

        #[Assert\Range(min: -180, max: 180, notInRangeMessage: 'Longitude must be between -180 and 180.')]
        public float $longitude,

        #[Assert\Range(min: 0, minMessage: 'Accuracy must be greater than or equal to 0.')]
        public float $accuracyMeters,
    ) {
    }
}
