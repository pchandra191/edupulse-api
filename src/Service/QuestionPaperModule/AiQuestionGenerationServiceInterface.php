<?php
declare(strict_types=1);

namespace App\Service\QuestionPaperModule;

use App\DTO\QuestionPaperModule\GenerateAiQuestionResponseDTO;

interface AiQuestionGenerationServiceInterface
{
    /**
     * Generate a single question based on the provided context.
     *
     * @throws \App\Common\Exception\BusinessException
     */
    public function generateQuestion(
        string $subject,
        string $topic,
        string $type,
        string $difficulty,
        int $marks,
        ?string $instruction
    ): GenerateAiQuestionResponseDTO;
}
