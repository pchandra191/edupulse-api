<?php
declare(strict_types=1);

namespace App\Message\Analysis;

use Symfony\Component\Uid\Uuid;

class ProcessAnalysisMessage
{
    public function __construct(
        public string $analysisId,
        public string $studentId,
        public string $action = 'prepare',
        public bool $isRetry = false,
        public int $attemptId = 0,
    ) {
    }

    public static function create(string $analysisId, string $studentId): self
    {
        return new self($analysisId, $studentId, 'prepare', false, 0);
    }

    public static function createAnalyze(string $analysisId, string $studentId): self
    {
        return new self($analysisId, $studentId, 'analyze', false, 0);
    }

    public static function createRetry(string $analysisId, string $studentId, int $attemptId): self
    {
        return new self($analysisId, $studentId, 'analyze', true, $attemptId);
    }
}