<?php
declare(strict_types=1);

namespace App\DTO\Institute;

use Symfony\Component\Validator\Constraints as Assert;

readonly class InstituteProfileUpdateRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,

        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public string $email,

        #[Assert\Length(max: 20)]
        public ?string $phone = null,

        #[Assert\Url]
        #[Assert\Length(max: 255)]
        public ?string $website = null,

        #[Assert\Length(max: 255)]
        public ?string $logo = null,

        #[Assert\Length(max: 255)]
        public ?string $addressLine1 = null,

        #[Assert\Length(max: 255)]
        public ?string $addressLine2 = null,

        #[Assert\Length(max: 100)]
        public ?string $city = null,

        #[Assert\Length(max: 100)]
        public ?string $state = null,

        #[Assert\Length(max: 100)]
        public ?string $country = null,

        #[Assert\Length(max: 20)]
        public ?string $postalCode = null,

        #[Assert\Length(max: 100)]
        public ?string $timezone = null,

        #[Assert\Length(max: 3)]
        public ?string $currency = null,
    ) {
    }
}
