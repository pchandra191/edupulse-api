<?php
declare(strict_types=1);

namespace App\DTO\ExaminationModule;

readonly class CreatedSessionDTO
{
    public function __construct(
        public string $examSubjectId,
        public string $examSessionId,
        public string $status,
        public bool $created,
        public bool $reused,
        public bool $isValidated,
    ) {
    }
}
