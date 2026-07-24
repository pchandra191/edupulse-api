<?php
declare(strict_types=1);

namespace App\DTO\User;

use App\Enum\UserStatus;
use Symfony\Component\Validator\Constraints as Assert;

readonly class StatusUpdateRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public UserStatus $status,
    ) {
    }
}
