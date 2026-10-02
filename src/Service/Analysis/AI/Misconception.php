<?php
declare(strict_types=1);

namespace App\Service\Analysis\AI;

readonly class Misconception
{
    public function __construct(
        public bool $identified,
        public ?string $description,
        public ?float $confidence,
    ) {
    }

    public static function notIdentified(): self
    {
        return new self(false, null, null);
    }

    public static function identified(string $description, float $confidence): self
    {
        return new self(true, $description, $confidence);
    }
}