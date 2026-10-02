<?php
declare(strict_types=1);

namespace App\Service\Analysis\AI;

use App\Document\Analysis;
use Psr\Log\LoggerInterface;

readonly class MistakeClassificationService
{
    public function __construct(
        private AiProviderInterface $aiProvider,
        private LoggerInterface $logger,
    ) {
    }

    /** @return array<array{questionNumber: string, mistakeType: string, description: string, confidence: float}> */
    public function classifyMistakes(Analysis $analysis, array $evaluationResults): array
    {
        $questions = $analysis->getExam()->getExtractedQuestions();
        $questionMap = [];
        foreach ($questions as $q) {
            $qNum = $q['questionNumber']['value'] ?? ($q['questionNumber'] ?? '');
            $questionMap[$qNum] = $q;
        }

        $mistakes = [];

        foreach ($evaluationResults as $result) {
            if ($result->resultStatus === ResultStatus::Correct || $result->resultStatus === ResultStatus::Unanswered) {
                continue;
            }

            $question = $questionMap[$result->questionNumber] ?? null;
            
            $mistake = [
                'questionNumber' => $result->questionNumber,
                'mistakeType' => $result->errorType->value,
                'description' => $result->errorDescription ?? $this->getDefaultDescription($result->errorType),
                'confidence' => $result->confidence,
            ];

            if ($result->misconception->identified) {
                $mistake['misconception'] = [
                    'description' => $result->misconception->description,
                    'confidence' => $result->misconception->confidence ?? $result->confidence,
                ];
            }

            $mistakes[] = $mistake;
        }

        return $mistakes;
    }

    private function getDefaultDescription(ErrorType $errorType): string
    {
        return match ($errorType) {
            ErrorType::Conceptual => 'Fundamental misunderstanding of the concept',
            ErrorType::Calculation => 'Arithmetic or computational error',
            ErrorType::Formula => 'Incorrect formula selection or application',
            ErrorType::Procedural => 'Incorrect procedure or step sequence',
            ErrorType::Misinterpretation => 'Misread or misunderstood the question',
            ErrorType::Careless => 'Avoidable slip or oversight',
            ErrorType::IncompleteReasoning => 'Reasoning not fully developed',
            ErrorType::KnowledgeGap => 'Missing prerequisite knowledge',
            ErrorType::TimeOrAttempt => 'Insufficient time or incomplete attempt',
            ErrorType::None => 'No error identified',
            ErrorType::Unknown => 'Error type could not be determined',
        };
    }
}