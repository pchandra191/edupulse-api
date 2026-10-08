<?php

declare(strict_types=1);

namespace App\DTO\Analysis\Report;

readonly class ResultSummaryReportData
{
    public function __construct(
        public ?int $score = null,
        public ?float $percentage = null,
        public int $totalQuestions = 0,
        public int $correct = 0,
        public int $partiallyCorrect = 0,
        public int $incorrect = 0,
        public int $unanswered = 0,
    ) {
    }
}
