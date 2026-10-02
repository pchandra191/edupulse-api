<?php
declare(strict_types=1);

namespace App\Message;

readonly class ProcessAnalysisAIMessage
{
    public function __construct(
        public string $analysisId,
        public string $studentId,
    ) {
    }
}