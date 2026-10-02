<?php
declare(strict_types=1);

namespace App\Service\Analysis\AI;

use App\Document\Analysis;
use Psr\Log\LoggerInterface;

readonly class RecommendationService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /** @return array<array{action: string, reason: string, priority: string}> */
    public function generateRecommendations(
        Analysis $analysis,
        array $evaluationResults,
        array $mistakes,
        array $topicClassifications,
        array $patterns
    ): array {
        $recommendations = [];

        // Recommendation based on overall performance
        $summary = $this->calculateSummary($evaluationResults);
        
        if ($summary['percentage'] < 40) {
            $recommendations[] = [
                'action' => 'Review fundamental concepts across all topics',
                'reason' => 'Overall score is below 40%, indicating broad knowledge gaps',
                'priority' => 'HIGH',
            ];
        } elseif ($summary['percentage'] < 60) {
            $recommendations[] = [
                'action' => 'Focus on strengthening weak areas identified in this analysis',
                'reason' => 'Score is between 40-60%, targeted practice can significantly improve results',
                'priority' => 'MEDIUM',
            ];
        }

        // Recommendations from topic classifications
        foreach ($topicClassifications as $topic) {
            if (in_array($topic['masteryLevel'], ['STRUGGLING', 'DEVELOPING'])) {
                $recommendations[] = [
                    'action' => "Practice more questions on {$topic['topic']}",
                    'reason' => "Mastery level is {$topic['masteryLevel']} with confidence {$topic['confidence']}",
                    'priority' => $topic['masteryLevel'] === 'STRUGGLING' ? 'HIGH' : 'MEDIUM',
                ];
            }
        }

        // Recommendations from patterns
        foreach ($patterns as $pattern) {
            $rec = $this->getRecommendationForPattern($pattern);
            if ($rec) {
                $recommendations[] = $rec;
            }
        }

        // Recommendation for unanswered questions
        $unanswered = array_filter($evaluationResults, fn($r) => $r->resultStatus === ResultStatus::Unanswered);
        if (count($unanswered) > 0) {
            $recommendations[] = [
                'action' => 'Practice time management strategies for exams',
                'reason' => count($unanswered) . ' questions left unanswered, suggesting time pressure',
                'priority' => 'MEDIUM',
            ];
        }

        // Deduplicate by action
        $unique = [];
        foreach ($recommendations as $rec) {
            $key = $rec['action'];
            if (!isset($unique[$key]) || $this->priorityWeight($rec['priority']) > $this->priorityWeight($unique[$key]['priority'])) {
                $unique[$key] = $rec;
            }
        }

        // Sort by priority
        uasort($unique, fn($a, $b) => $this->priorityWeight($b['priority']) <=> $this->priorityWeight($a['priority']));

        return array_values(array_slice($unique, 0, 10));
    }

    private function calculateSummary(array $evaluationResults): array
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

    private function getRecommendationForPattern(array $pattern): ?array
    {
        return match ($pattern['type']) {
            'REPEATED_ERROR_TYPE' => [
                'action' => "Address recurring {$pattern['category']} errors through targeted practice",
                'reason' => "{$pattern['occurrences']} occurrences of {$pattern['category']} mistakes detected",
                'priority' => 'HIGH',
            ],
            'TOPIC_WEAKNESS' => [
                'action' => "Strengthen understanding of {$pattern['topic']}",
                'reason' => "{$pattern['occurrences']} errors related to {$pattern['topic']} detected",
                'priority' => 'HIGH',
            ],
            'UNANSWERED_CLUSTER' => [
                'action' => 'Work on exam time management and question prioritization',
                'reason' => "{$pattern['occurrences']} questions left unanswered",
                'priority' => 'MEDIUM',
            ],
            'DIFFICULTY_STRUGGLE' => [
                'action' => "Practice more {$pattern['topic']} difficulty questions",
                'reason' => "Low performance on {$pattern['topic']} questions",
                'priority' => 'MEDIUM',
            ],
            default => null,
        };
    }

    private function priorityWeight(string $priority): int
    {
        return match ($priority) {
            'HIGH' => 3,
            'MEDIUM' => 2,
            'LOW' => 1,
            default => 0,
        };
    }
}