<?php
declare(strict_types=1);

namespace App\DTO\Analysis;

use App\Service\Analysis\Processing\ProvenanceValue;

readonly class ExtractedAnswerSheetMetadata
{
    public function __construct(
        public ?ProvenanceValue $studentName = null,
        public ?ProvenanceValue $rollNumber = null,
        public ?ProvenanceValue $subject = null,
        public ?ProvenanceValue $examTitle = null,
        public ?ProvenanceValue $academicYear = null,
        public ?ProvenanceValue $class = null,
        public ?ProvenanceValue $section = null,
        public ?ProvenanceValue $totalMarks = null,
        public ?ProvenanceValue $scoredMarks = null,
        public ?ProvenanceValue $examDate = null,
        public ?ProvenanceValue $examSubType = null,
        public ?ProvenanceValue $instituteName = null,
        public ?string $extractionResult = null,
        public ?float $extractionConfidence = null,
    ) {
    }
}