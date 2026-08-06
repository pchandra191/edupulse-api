<?php
declare(strict_types=1);

namespace App\DTO\ClassModule;

use Symfony\Component\Validator\Constraints as Assert;

readonly class ClassFilterRequest
{
    public function __construct(
        #[Assert\Length(max: 255)]
        public ?string $search = null,

        public ?string $academicYearId = null,

        public ?string $status = null,

        public int $page = 1,

        public int $limit = 10,

        #[Assert\Length(max: 50)]
        public string $sortBy = 'createdAt',

        #[Assert\Length(max: 4)]
        public string $sortOrder = 'DESC',
    ) {
    }
}
