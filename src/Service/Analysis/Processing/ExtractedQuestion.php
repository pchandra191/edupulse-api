<?php
declare(strict_types=1);

namespace App\Service\Analysis\Processing;

readonly class ExtractedQuestion
{
    public function __construct(
        public ProvenanceValue $questionNumber,
        public ProvenanceValue $questionText,
        public ProvenanceValue $marks,
        public ProvenanceValue $type,
        public array $source,
        public ?ProvenanceValue $answerText = null,
        public ?array $answerSource = null,
    ) {
    }

    public function toArray(): array
    {
        $result = [
            'questionNumber' => $this->questionNumber->toArray(),
            'questionText' => $this->questionText->toArray(),
            'marks' => $this->marks->toArray(),
            'type' => $this->type->toArray(),
            'source' => $this->source,
        ];

        if ($this->answerText !== null) {
            $result['answerText'] = $this->answerText->toArray();
        }
        
        if ($this->answerSource !== null) {
            $result['answerSource'] = $this->answerSource;
        }

        return $result;
    }
}