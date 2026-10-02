<?php
declare(strict_types=1);

namespace App\Service\Analysis\AI;

use App\Document\Analysis;
use Psr\Log\LoggerInterface;

readonly class PatternAnalysisService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /** @return array<array{type: string, category: string, topic: string, occurrences: int, confidence: float}> */
    public function analyzePatterns(Analysis $analysis, array $evaluationResults, array $mistakes): array
    {
        $patterns = [];

        // Pattern 1: Repeated error types
        $errorTypeCounts = [];
        foreach ($mistakes as $mistake) {
            $type = $mistake['mistakeType'];
            $errorTypeCounts[$type] = ($errorTypeCounts[$type] ?? 0) + 1;
        }

        foreach ($errorTypeCounts as $type => $count) {
            if ($count >= 2) {
                $patterns[] = [
                    'type' => 'REPEATED_ERROR_TYPE',
                    'category' => $type,
                    'topic' => 'general',
                    'occurrences' => $count,
                    'confidence' => min(1.0, 0.5 + ($count * 0.1)),
                ];
            }
        }

        // Pattern 2: Topic-specific weakness
        $topicErrorCounts = [];
        foreach ($mistakes as $mistake) {
            $questionNumber = $mistake['questionNumber'];
            $question = $this->findQuestion($analysis, $questionNumber);
            if ($question && !empty($question['topics'])) {
                foreach ($question['topics'] as $topic) {
                    $topicErrorCounts[$topic] = ($topicErrorCounts[$topic] ?? 0) + 1;
                }
            }
        }

        foreach ($topicErrorCounts as $topic => $count) {
            if ($count >= 2) {
                $patterns[] = [
                    'type' => 'TOPIC_WEAKNESS',
                    'category' => 'knowledge_gap',
                    'topic' => $topic,
                    'occurrences' => $count,
                    'confidence' => min(1.0, 0.6 + ($count * 0.08)),
                ];
            }
        }

        // Pattern 3: Unanswered questions cluster
        $unansweredCount = 0;
        foreach ($evaluationResults as $result) {
            if ($result->resultStatus === ResultStatus::Unanswered) {
                $unansweredCount++;
            }
        }

        if ($unansweredCount >= 3) {
            $patterns[] = [
                'type' => 'UNANSWERED_CLUSTER',
                'category' => 'time_management',
                'topic' => 'general',
                'occurrences' => $unansweredCount,
                'confidence' => min(1.0, 0.7 + ($unansweredCount * 0.05)),
            ];
        }

        // Pattern 4: Difficulty-specific performance
        $difficultyPerformance = [];
        foreach ($evaluationResults as $result) {
            if ($result->difficulty !== Difficulty::Unknown) {
                $diff = $result->difficulty->value;
                if (!isset($difficultyPerformance[$diff])) {
                    $difficultyPerformance[$diff] = ['total' => 0, 'earned' => 0];
                }
                $difficultyPerformance[$diff]['total'] += $result->maxMarks;
                $difficultyPerformance[$diff]['earned'] += $result->marksAwarded;
            }
        }

        foreach ($difficultyPerformance as $difficulty => $perf) {
            if ($perf['total'] > 0) {
                $percentage = ($perf['earned'] / $perf['total']) * 100;
                if ($percentage < 40) {
                    $patterns[] = [
                        'type' => 'DIFFICULTY_STRUGGLE',
                        'category' => 'difficulty_gap',
                        'topic' => strtolower($difficulty),
                        'occurrences' => 1,
                        'confidence' => min(1.0, 0.5 + ((100 - $percentage) * 0.005)),
                    ];
                }
            }
        }

        return $patterns;
    }

    private function findQuestion(Analysis $analysis, string $questionNumber): ?array
    {
        foreach ($analysis->getExam()->getExtractedQuestions() as $question) {
            $qNum = $question['questionNumber']['value'] ?? ($question['questionNumber'] ?? '');
            if (($qNum ?? '') === $questionNumber) {
                return $question;
            }
        }
        return null;
    }
}