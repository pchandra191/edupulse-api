<?php

declare(strict_types=1);

namespace App\DTO\Analysis\Report;

readonly class AiAnswerSheetReportData
{
    /**
     * @param array<QuestionReportData> $questions
     * @param array<string> $strengths
     * @param array<string> $weaknesses
     * @param array<string> $needsAttention
     * @param array<string> $conceptGaps
     * @param array<string> $recommendations
     * @param array<ConceptMasteryReportData> $conceptMastery
     */
    public function __construct(
        public StudentReportData $student,
        public ExamReportData $exam,
        public ResultSummaryReportData $result,
        public array $questions,
        public array $strengths,
        public array $weaknesses,
        public array $needsAttention,
        public array $conceptGaps,
        public array $recommendations,
        public array $conceptMastery,
    ) {
    }
}
