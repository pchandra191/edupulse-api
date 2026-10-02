<?php
declare(strict_types=1);

namespace App\Service\Analysis\AI;

use App\Document\Analysis;
use Psr\Log\LoggerInterface;

readonly class TopicClassificationService
{
    public function __construct(
        private AiProviderInterface $aiProvider,
        private LoggerInterface $logger,
    ) {
    }

    /** @return array<array{topic: string, confidence: float, questions: array<string>, masteryLevel: string}> */
    public function classifyTopics(Analysis $analysis, array $evaluationResults): array
    {
        $topicScores = [];
        $topicQuestions = [];

        foreach ($evaluationResults as $result) {
            foreach ($result->topics as $topic) {
                $name = $topic['name'];
                $confidence = $topic['confidence'];

                if (!isset($topicScores[$name])) {
                    $topicScores[$name] = [];
                    $topicQuestions[$name] = [];
                }

                $topicScores[$name][] = $confidence;
                $topicQuestions[$name][] = $result->questionNumber;
            }
        }

        $classifications = [];

        foreach ($topicScores as $topic => $scores) {
            $avgConfidence = array_sum($scores) / count($scores);
            $masteryLevel = $this->calculateMasteryLevel($topic, $evaluationResults, $topicQuestions[$topic]);

            $classifications[] = [
                'topic' => $topic,
                'confidence' => round($avgConfidence, 2),
                'questions' => $topicQuestions[$topic],
                'masteryLevel' => $masteryLevel,
            ];
        }

        // Sort by confidence descending
        usort($classifications, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

        return $classifications;
    }

    private function calculateMasteryLevel(string $topic, array $evaluationResults, array $questionNumbers): string
    {
        $topicResults = array_filter($evaluationResults, fn($r) => in_array($r->questionNumber, $questionNumbers));
        
        if (empty($topicResults)) {
            return 'UNKNOWN';
        }

        $totalMarks = array_sum(array_map(fn($r) => $r->maxMarks, $topicResults));
        $awardedMarks = array_sum(array_map(fn($r) => $r->marksAwarded, $topicResults));

        if ($totalMarks === 0) {
            return 'UNKNOWN';
        }

        $percentage = ($awardedMarks / $totalMarks) * 100;

        return match (true) {
            $percentage >= 80 => 'MASTERED',
            $percentage >= 60 => 'PROFICIENT',
            $percentage >= 40 => 'DEVELOPING',
            default => 'STRUGGLING',
        };
    }
}