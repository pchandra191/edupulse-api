<?php
declare(strict_types=1);

namespace App\DTO\Analysis;

use Symfony\Component\Validator\Constraints as Assert;

readonly class StartAnalysisRequestDTO
{
    public function __construct() {
    }

    public static function fromArray(array $data): self
    {
        return new self();
    }
}