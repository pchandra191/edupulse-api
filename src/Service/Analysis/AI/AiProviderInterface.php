<?php
declare(strict_types=1);

namespace App\Service\Analysis\AI;

interface AiProviderInterface
{
    public function evaluateQuestion(QuestionEvaluationRequest $request): QuestionEvaluationResult;
    
    public function getProviderName(): string;
    
    public function getModelName(): string;
}