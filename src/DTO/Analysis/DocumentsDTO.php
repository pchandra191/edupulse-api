<?php

declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class DocumentsDTO
{
    public function __construct(
        public DocumentFileDTO $questionPaper,
        public DocumentFileDTO $answerSheet,
    ) {
    }
}