<?php

declare(strict_types=1);

namespace App\DTO\Analysis;

use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateAnalysisRequestDTO
{
    public function __construct(
        #[Assert\Choice(choices: ['EXTERNAL_UPLOAD', 'EDUPULSE_EXAM'])]
        public string $source = 'EXTERNAL_UPLOAD',
        #[Assert\Uuid]
        public ?string $examSetId = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        // Accept both 'source' (backend contract) and 'sourceType' (frontend field)
        $source = $data['source'] ?? $data['sourceType'] ?? 'EXTERNAL_UPLOAD';

        return new self(
            source: $source,
            examSetId: $data['examSetId'] ?? null,
        );
    }
}