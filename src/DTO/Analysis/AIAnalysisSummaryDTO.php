<?php
declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class AIAnalysisSummaryDTO
{
    public function __construct(
        public int $totalMarks,
        public int $marksAwarded,
        public int $percentage,
        public int $attempted,
        public int $correct,
        public int $partiallyCorrect,
        public int $incorrect,
        public int $unanswered,
    ) {
    }
}