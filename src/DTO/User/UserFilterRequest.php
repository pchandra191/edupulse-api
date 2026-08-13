<?php
declare(strict_types=1);

namespace App\DTO\User;

use Symfony\Component\Validator\Constraints as Assert;

readonly class UserFilterRequest
{
    public function __construct(
        #[Assert\Length(max: 255)]
        public ?string $search = null,

        public ?string $role = null,

        public ?string $status = null,

        #[Assert\Length(max: 100)]
        public ?string $department = null,

        #[Assert\Length(max: 36)]
        public ?string $instituteId = null,

        #[Assert\Positive]
        public int $page = 1,

        #[Assert\Positive]
        public int $limit = 10,

        #[Assert\Length(max: 50)]
        public string $sortBy = 'createdAt',

        #[Assert\Length(max: 4)]
        public string $sortOrder = 'DESC',
    ) {
    }
}
