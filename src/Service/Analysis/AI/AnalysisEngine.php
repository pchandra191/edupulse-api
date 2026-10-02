<?php
declare(strict_types=1);

namespace App\Service\Analysis\AI;

use App\Document\Analysis;
use App\DTO\Analysis\AIAnalysisQuestionDTO;
use App\DTO\Analysis\AIAnalysisPatternDTO;
use App\DTO\Analysis\AIAnalysisRecommendationDTO;
use App\DTO\Analysis\AIAnalysisResultDTO;
use App\DTO\Analysis\AIAnalysisSummaryDTO;
use Psr\Log\LoggerInterface;

readonly class AnalysisEngine
{
    public function __construct(
        private QuestionEvaluationService $questionEvaluationService,
        private MistakeClassificationService $mistakeClassificationService,
        private TopicClassificationService $topicClassificationService,
        private PatternAnalysisService $patternAnalysisService,
        private RecommendationService $recommendationService,
        private LoggerInterface $logger,
    ) {
    }

    public function runAnalysis(Analysis $analysis): AIAnalysisResultDTO
    {
        $this->logger->info('Starting AI analysis', [
            'analysis_id' => $analysis->getAnalysisId(),
        ]);

        // Step 1: Question Evaluation
        $evaluationResults = $this->questionEvaluationService->evaluateQuestions($analysis);
        
        // Step 2: Mistake Classification
        $mistakes = $this->mistakeClassificationService->classifyMistakes($analysis, $evaluationResults);
        
        // Step 3: Topic Classification
        $topicClassifications = $this->topicClassificationService->classifyTopics($analysis, $evaluationResults);
        
        // Step 4: Pattern Analysis
        $patterns = $this->patternAnalysisService->analyzePatterns($analysis, $evaluationResults, $mistakes);
        
        // Step 5: Generate Recommendations
        $recommendations = $this->recommendationService->generateRecommendations(
            $analysis,
            $evaluationResults,
            $mistakes,
            $topicClassifications,
            $patterns
        );

        // Build summary
        $summary = $this->buildSummary($evaluationResults);

        // Build question DTOs
        $questionDTOs = array_map(fn($r) => new AIAnalysisQuestionDTO(
            questionNumber: $r->questionNumber,
            resultStatus: $r->resultStatus->value,
            marksAwarded: $r->marksAwarded,
            maxMarks: $r->maxMarks,
            answerQuality: $r->answerQuality,
            reasoningQuality: $r->reasoningQuality,
            confidence: $r->confidence,
            errorType: $r->errorType->value,
            errorDescription: $r->errorDescription,
            difficulty: $r->difficulty->value,
            difficultyConfidence: $r->difficultyConfidence,
            topics: $r->topics,
            concepts: $r->concepts,
            misconception: $r->misconception->identified ? [
                'description' => $r->misconception->description,
                'confidence' => $r->misconception->confidence,
            ] : null,
        ), $evaluationResults);

        // Build pattern DTOs
        $patternDTOs = array_map(fn($p) => new AIAnalysisPatternDTO(
            type: $p['type'],
            category: $p['category'],
            topic: $p['topic'],
            occurrences: $p['occurrences'],
            confidence: $p['confidence'],
        ), $patterns);

        // Build recommendation DTOs
        $recommendationDTOs = array_map(fn($r) => new AIAnalysisRecommendationDTO(
            action: $r['action'],
            reason: $r['reason'],
            priority: $r['priority'],
        ), $recommendations);

        $result = new AIAnalysisResultDTO(
            version: '1.0',
            provider: $this->questionEvaluationService->getAiProvider()->getProviderName(),
            model: $this->questionEvaluationService->getAiProvider()->getModelName(),
            summary: new AIAnalysisSummaryDTO(
                totalMarks: $summary['totalMarks'],
                marksAwarded: $summary['marksAwarded'],
                percentage: $summary['percentage'],
                attempted: $summary['attempted'],
                correct: $summary['correct'],
                partiallyCorrect: $summary['partiallyCorrect'],
                incorrect: $summary['incorrect'],
                unanswered: $summary['unanswered'],
            ),
            questions: $questionDTOs,
            patterns: $patternDTOs,
            recommendations: $recommendationDTOs,
            completedAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        );

        $this->logger->info('AI analysis completed', [
            'analysis_id' => $analysis->getAnalysisId(),
            'questions_evaluated' => count($evaluationResults),
            'mistakes_found' => count($mistakes),
            'patterns_found' => count($patterns),
            'recommendations_generated' => count($recommendations),
        ]);

        return $result;
    }

    private function buildSummary(array $evaluationResults): array
    {
        $totalMarks = 0;
        $marksAwarded = 0;
        $attempted = 0;
        $correct = 0;
        $partiallyCorrect = 0;
        $incorrect = 0;
        $unanswered = 0;

        foreach ($evaluationResults as $result) {
            $totalMarks += $result->maxMarks;
            $marksAwarded += $result->marksAwarded;

            switch ($result->resultStatus) {
                case ResultStatus::Correct:
                    $correct++;
                    $attempted++;
                    break;
                case ResultStatus::PartiallyCorrect:
                    $partiallyCorrect++;
                    $attempted++;
                    break;
                case ResultStatus::Incorrect:
                    $incorrect++;
                    $attempted++;
                    break;
                case ResultStatus::Unanswered:
                    $unanswered++;
                    break;
            }
        }

        $percentage = $totalMarks > 0 ? round(($marksAwarded / $totalMarks) * 100) : 0;

        return compact('totalMarks', 'marksAwarded', 'percentage', 'attempted', 'correct', 'partiallyCorrect', 'incorrect', 'unanswered');
    }
}