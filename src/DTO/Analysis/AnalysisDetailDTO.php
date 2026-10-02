<?php

declare(strict_types=1);

namespace App\DTO\Analysis;

readonly class AnalysisDetailDTO
{
    public function __construct(
        public string $analysisId,
        public string $studentId,
        public string $source,
        public string $status,
        public DocumentsDTO $documents,
        public ExamDTO $exam,
        public ProcessingDTO $processing,
        public ?array $aiAnalysis,
        public ?array $examSet,
        public ?string $subject,
        public ?int $totalMarks,
        public ?int $scoredMarks,
        public ?string $studentName,
        public ?string $rollNumber,
        public bool $metadataReady,
        public bool $answerSheetUploaded,
        public string $createdAt,
        public string $updatedAt,
        public ?array $documentClassification = null,
    ) {
    }
}