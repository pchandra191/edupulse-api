<?php

declare(strict_types=1);

namespace App\DTO\Analysis\Report;

readonly class QuestionReportData
{
    /**
     * @param array<string> $missingPoints
     * @param array<string> $evidence
     */
    public function __construct(
        public string $number,
        public string $question,
        public string $studentAnswer,
        public ?int $marksAwarded,
        public ?int $maxMarks,
        public string $evaluationStatus,
        public ?string $subQuestion = null,
        public ?string $correctness = null,
        public ?string $whatStudentDidWell = null,
        public ?string $mistake = null,
        public ?string $whyItIsWrong = null,
        public array $missingPoints = [],
        public ?ConceptReportData $concept = null,
        public ?ImprovementReportData $improvement = null,
        public array $evidence = [],
    ) {
    }
}
