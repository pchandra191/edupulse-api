<?php
declare(strict_types=1);

namespace App\MessageHandler;

use App\Document\Analysis;
use App\Entity\Analysis as PgAnalysis;
use App\Message\ProcessAnalysisAIMessage;
use App\Repository\AnalysisRepository;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use App\Service\Analysis\AI\AnalysisEngine;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class ProcessAnalysisAIMessageHandler
{
    public function __construct(
        private MongoAnalysisRepository $analysisRepository,
        private AnalysisRepository $pgAnalysisRepository,
        private EntityManagerInterface $entityManager,
        private DocumentManager $documentManager,
        private AnalysisEngine $analysisEngine,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessAnalysisAIMessage $message): void
    {
        $analysisId = $message->analysisId;
        $studentId = $message->studentId;

        $this->logger->info('Processing AI analysis message', ['analysis_id' => $analysisId, 'student_id' => $studentId]);

        // Find analysis in MongoDB with studentId for authorization
        $analysis = $this->analysisRepository->findByAnalysisIdAndStudentId($analysisId, $studentId);
        
        if (!$analysis) {
            $this->logger->error('Analysis not found for AI processing or access denied', ['analysis_id' => $analysisId, 'student_id' => $studentId]);
            return;
        }

        // Check if already analyzed (idempotency) - new lifecycle: DONE
        if ($analysis->getStatus() === 'DONE' && $analysis->getAiAnalysis() !== null) {
            $this->logger->info('Analysis already processed, skipping', ['analysis_id' => $analysisId]);
            return;
        }

        // Check if already analyzing (prevent duplicate processing)
        if ($analysis->getStatus() === 'PROCESSING') {
            $this->logger->info('Analysis already being processed, skipping', ['analysis_id' => $analysisId]);
            return;
        }

        // Verify current status allows transition to PROCESSING
        // New lifecycle: PUBLISHED or CONFIRMED (legacy) can start AI analysis
        $allowedFromStatuses = ['PUBLISHED', 'CONFIRMED'];
        if (!in_array($analysis->getStatus(), $allowedFromStatuses, true)) {
            $this->logger->warning('Cannot start AI analysis from current status', [
                'analysis_id' => $analysisId,
                'current_status' => $analysis->getStatus(),
                'allowed_from' => $allowedFromStatuses,
            ]);
            return;
        }

        try {
            // Update status to PROCESSING
            $analysis->setStatus('PROCESSING');
            $this->documentManager->flush();
            $this->syncPostgresStatus($analysisId, 'PROCESSING');

            // Check if there are existing evaluations from combined visual extraction
            $aiAnalysis = $analysis->getAiAnalysis() ?? [];
            $existingEvaluations = $aiAnalysis['questionEvaluations'] ?? [];
            $currentExamAnalysis = $aiAnalysis['currentExamAnalysis'] ?? null;
            $hasExistingEvaluations = !empty($existingEvaluations);

            if ($hasExistingEvaluations) {
                $this->logger->info('Reusing existing evaluations from combined visual extraction', [
                    'analysis_id' => $analysisId,
                    'question_evaluations_count' => count($existingEvaluations),
                    'has_current_exam_analysis' => is_array($currentExamAnalysis) && !empty(array_filter([
                        $currentExamAnalysis['strengths'] ?? [],
                        $currentExamAnalysis['weaknesses'] ?? [],
                        $currentExamAnalysis['needsAttention'] ?? [],
                        $currentExamAnalysis['conceptGaps'] ?? [],
                        $currentExamAnalysis['recommendations'] ?? [],
                    ])),
                ]);

                // Build summary from existing evaluations
                $summary = $this->buildSummaryFromEvaluations($existingEvaluations);
                
                // Build question DTOs from existing evaluations
                $questionDTOs = array_map(fn($q) => [
                    'questionNumber' => $q['questionNumber'],
                    'resultStatus' => $q['resultStatus'],
                    'marksAwarded' => $q['marksAwarded'],
                    'maxMarks' => $q['maxMarks'],
                    'answerQuality' => $q['answerQuality'],
                    'reasoningQuality' => $q['reasoningQuality'],
                    'confidence' => $q['confidence'],
                    'errorType' => $q['errorType'],
                    'errorDescription' => $q['errorDescription'],
                    'difficulty' => $q['difficulty'],
                    'difficultyConfidence' => $q['difficultyConfidence'],
                    'topics' => $q['topics'],
                    'concepts' => $q['concepts'],
                    'misconception' => $q['misconception'],
                ], $existingEvaluations);

                // Run pattern analysis and recommendations only (not question evaluation)
                $patterns = $this->runPatternAnalysis($analysis, $existingEvaluations);
                $recommendations = $this->runRecommendations($analysis, $existingEvaluations, $patterns, $currentExamAnalysis);

                $result = new \App\DTO\Analysis\AIAnalysisResultDTO(
                    version: '1.0',
                    provider: $existingEvaluations[0]['provider'] ?? 'OPENAI_VISION_COMBINED',
                    model: $existingEvaluations[0]['model'] ?? 'gpt-4o',
                    summary: new \App\DTO\Analysis\AIAnalysisSummaryDTO(
                        totalMarks: $summary['totalMarks'],
                        marksAwarded: $summary['marksAwarded'],
                        percentage: $summary['percentage'],
                        attempted: $summary['attempted'],
                        correct: $summary['correct'],
                        partiallyCorrect: $summary['partiallyCorrect'],
                        incorrect: $summary['incorrect'],
                        unanswered: $summary['unanswered'],
                    ),
                    questions: array_map(fn($q) => new \App\DTO\Analysis\AIAnalysisQuestionDTO(
                        questionNumber: $q['questionNumber'],
                        resultStatus: $q['resultStatus'],
                        marksAwarded: $q['marksAwarded'],
                        maxMarks: $q['maxMarks'],
                        answerQuality: $q['answerQuality'],
                        reasoningQuality: $q['reasoningQuality'],
                        confidence: $q['confidence'],
                        errorType: $q['errorType'],
                        errorDescription: $q['errorDescription'],
                        difficulty: $q['difficulty'],
                        difficultyConfidence: $q['difficultyConfidence'],
                        topics: $q['topics'],
                        concepts: $q['concepts'],
                        misconception: $q['misconception'],
                    ), $questionDTOs),
                    patterns: $patterns,
                    recommendations: $recommendations,
                    completedAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                );
            } else {
                // No existing evaluations - run full AI analysis
                $this->logger->info('Running full AI analysis', ['analysis_id' => $analysisId]);
                $result = $this->analysisEngine->runAnalysis($analysis);
            }

            // Store result in MongoDB
            $analysis->setAiAnalysis([
                'version' => $result->version,
                'provider' => $result->provider,
                'model' => $result->model,
                'summary' => [
                    'totalMarks' => $result->summary->totalMarks,
                    'marksAwarded' => $result->summary->marksAwarded,
                    'percentage' => $result->summary->percentage,
                    'attempted' => $result->summary->attempted,
                    'correct' => $result->summary->correct,
                    'partiallyCorrect' => $result->summary->partiallyCorrect,
                    'incorrect' => $result->summary->incorrect,
                    'unanswered' => $result->summary->unanswered,
                ],
                'questions' => array_map(fn($q) => [
                    'questionNumber' => $q->questionNumber,
                    'resultStatus' => $q->resultStatus,
                    'marksAwarded' => $q->marksAwarded,
                    'maxMarks' => $q->maxMarks,
                    'answerQuality' => $q->answerQuality,
                    'reasoningQuality' => $q->reasoningQuality,
                    'confidence' => $q->confidence,
                    'errorType' => $q->errorType,
                    'errorDescription' => $q->errorDescription,
                    'difficulty' => $q->difficulty,
                    'difficultyConfidence' => $q->difficultyConfidence,
                    'topics' => $q->topics,
                    'concepts' => $q->concepts,
                    'misconception' => $q->misconception,
                ], $result->questions),
                'patterns' => array_map(fn($p) => [
                    'type' => $p->type,
                    'category' => $p->category,
                    'topic' => $p->topic,
                    'occurrences' => $p->occurrences,
                    'confidence' => $p->confidence,
                ], $result->patterns),
                'recommendations' => array_map(fn($r) => [
                    'action' => $r->action,
                    'reason' => $r->reason,
                    'priority' => $r->priority,
                ], $result->recommendations),
                'completedAt' => $result->completedAt,
                'currentExamAnalysis' => $currentExamAnalysis ?? ($result->currentExamAnalysis ?? null),
            ]);

            $analysis->setStatus('DONE');
            $this->documentManager->flush();
            $this->syncPostgresStatus($analysisId, 'DONE');

            $this->logger->info('AI analysis completed successfully', ['analysis_id' => $analysisId]);

        } catch (\Throwable $e) {
            $this->logger->error('AI analysis failed', [
                'analysis_id' => $analysisId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $analysis->setStatus('PUBLISHED');
            $this->documentManager->flush();
            $this->syncPostgresStatus($analysisId, 'PUBLISHED');
        }
    }

    private function buildSummaryFromEvaluations(array $evaluations): array
    {
        $totalMarks = 0;
        $marksAwarded = 0;
        $attempted = 0;
        $correct = 0;
        $partiallyCorrect = 0;
        $incorrect = 0;
        $unanswered = 0;

        foreach ($evaluations as $eval) {
            $totalMarks += $eval['maxMarks'] ?? 0;
            $marksAwarded += $eval['marksAwarded'] ?? 0;

            switch ($eval['resultStatus'] ?? 'NOT_EVALUATABLE') {
                case 'CORRECT':
                    $correct++;
                    $attempted++;
                    break;
                case 'PARTIALLY_CORRECT':
                    $partiallyCorrect++;
                    $attempted++;
                    break;
                case 'INCORRECT':
                    $incorrect++;
                    $attempted++;
                    break;
                case 'UNANSWERED':
                    $unanswered++;
                    break;
                default:
                    // NOT_EVALUATABLE - don't count as attempted
                    break;
            }
        }

        $percentage = $totalMarks > 0 ? round(($marksAwarded / $totalMarks) * 100) : 0;

        return compact('totalMarks', 'marksAwarded', 'percentage', 'attempted', 'correct', 'partiallyCorrect', 'incorrect', 'unanswered');
    }

    private function runPatternAnalysis(Analysis $analysis, array $evaluations): array
    {
        // Reuse PatternAnalysisService if available
        // For now, return empty patterns - can be enhanced later
        return [];
    }

    private function runRecommendations(Analysis $analysis, array $evaluations, array $patterns, ?array $currentExamAnalysis): array
    {
        // Use existing recommendations from currentExamAnalysis if available
        if ($currentExamAnalysis && !empty($currentExamAnalysis['recommendations'])) {
            return array_map(fn($r) => [
                'action' => $r,
                'reason' => 'From combined visual extraction',
                'priority' => 'MEDIUM',
            ], $currentExamAnalysis['recommendations']);
        }

        return [];
    }

    private function syncPostgresStatus(string $analysisId, string $status): void
    {
        try {
            $this->entityManager->beginTransaction();
            $pgAnalysis = $this->pgAnalysisRepository->findByAnalysisId($analysisId);
            if ($pgAnalysis) {
                $pgAnalysis->setStatus($status);
                $this->entityManager->flush();
                $this->entityManager->commit();
            }
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            $this->logger->error('Failed to update PostgreSQL analysis status', [
                'analysis_id' => $analysisId,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - MongoDB is source of truth, log and continue
        }
    }
}