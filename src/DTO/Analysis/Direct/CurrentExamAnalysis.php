<?php

declare(strict_types=1);

namespace App\DTO\Analysis\Direct;

readonly class CurrentExamAnalysis
{
    /**
     * @param array<string> $strengths
     * @param array<string> $weaknesses
     * @param array<string> $needsAttention
     * @param array<string> $conceptGaps
     * @param array<string> $recommendations
     * @param array<ConceptAssessment> $conceptAssessments
     * @param array<Mistake> $mistakes
     * @param array<EvidenceReference> $evidence
     */
    public function __construct(
        public ?Performance $performance = null,
        public array $strengths = [],
        public array $weaknesses = [],
        public array $needsAttention = [],
        public array $conceptGaps = [],
        public array $recommendations = [],
        public array $conceptAssessments = [],
        public array $mistakes = [],
        public array $evidence = [],
    ) {
    }
}