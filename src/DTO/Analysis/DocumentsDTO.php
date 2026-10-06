<?php

declare(strict_types=1);

namespace App\DTO\Analysis;

/** @var DocumentFileDTO[] */
readonly class DocumentsDTO
{
    public function __construct(
        public DocumentFileDTO $questionPaper,
        /** @var DocumentFileDTO[] */
        public array $answerSheets = [],
    ) {
    }

    public function getAnswerSheetCount(): int
    {
        return count($this->answerSheets);
    }

    public function getFirstAnswerSheet(): ?DocumentFileDTO
    {
        return $this->answerSheets[0] ?? null;
    }
}