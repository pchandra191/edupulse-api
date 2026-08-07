<?php
declare(strict_types=1);

namespace App\DTO\SubjectModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UpdateSubjectRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public ?string $name = null,

        #[Assert\NotBlank]
        #[Assert\Length(max: 30)]
        public ?string $code = null,

        #[Assert\Length(max: 1000)]
        public ?string $description = null,

        #[Assert\NotBlank]
        public ?string $status = null,
    ) {
    }
}
