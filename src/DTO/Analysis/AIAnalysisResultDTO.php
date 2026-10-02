<?php
declare(strict_types=1);

namespace App\DTO\Analysis;

/** @param array<AIAnalysisQuestionDTO> $questions */
/** @param array<AIAnalysisPatternDTO> $patterns */
/** @param array<AIAnalysisRecommendationDTO> $recommendations */
readonly class AIAnalysisResultDTO
{
    public function __construct(
        public string $version,
        public string $provider,
        public string $model,
        public AIAnalysisSummaryDTO $summary,
        public array $questions,
        public array $patterns,
        public array $recommendations,
        public string $completedAt,
    ) {
    }
}