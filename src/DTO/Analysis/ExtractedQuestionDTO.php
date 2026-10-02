<?php
declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class ExtractedQuestionDTO
{
    public function __construct(
        public ProvenanceValueDTO $questionNumber,
        public ProvenanceValueDTO $questionText,
        public ProvenanceValueDTO $marks,
        public ProvenanceValueDTO $type,
        public array $source,
        public ?ProvenanceValueDTO $answerText = null,
        public ?array $answerSource = null,
    ) {
    }
}