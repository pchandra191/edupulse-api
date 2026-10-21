<?php

declare(strict_types=1);

namespace App\Service\StudentIntelligence;

use App\DTO\StudentIntelligence\AcademicIntelligenceResponseDTO;
use App\DTO\StudentIntelligence\DashboardAICoachInsightDTO;
use App\DTO\StudentIntelligence\DashboardLearningHealthDTO;
use App\DTO\StudentIntelligence\DashboardPerformanceDTO;
use App\DTO\StudentIntelligence\DashboardRecentTestDTO;
use App\DTO\StudentIntelligence\DashboardStudyStreakDTO;
use App\DTO\StudentIntelligence\DashboardSubjectPerformanceDTO;
use App\DTO\StudentIntelligence\DashboardTodaysGoalDTO;
use App\DTO\StudentIntelligence\DashboardUpcomingTestDTO;
use App\DTO\StudentIntelligence\DashboardWeakestTopicDTO;
use App\DTO\StudentIntelligence\HistoricalPointDTO;
use App\Document\Analysis;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use Psr\Log\LoggerInterface;

readonly class AcademicIntelligenceService
{
    private const STATUS_DONE = 'DONE';

    private const CONCEPT_STRONG = 'STRONG';
    private const CONCEPT_DEVELOPING = 'DEVELOPING';
    private const CONCEPT_NEEDS_ATTENTION = 'NEEDS_ATTENTION';
    private const CONCEPT_INSUFFICIENT = 'INSUFFICIENT_EVIDENCE';

    private const EVAL_CORRECT = 'CORRECT';
    private const EVAL_PARTIALLY_CORRECT = 'PARTIALLY_CORRECT';
    private const EVAL_INCORRECT = 'INCORRECT';

    private const TREND_IMPROVING = 'IMPROVING';
    private const TREND_DECLINING = 'DECLINING';
    private const TREND_STABLE = 'STABLE';
    private const TREND_NEW = 'NEW';
    private const TREND_INSUFFICIENT = 'INSUFFICIENT_DATA';

    private const LEARNING_HEALTH_WEIGHT_PERFORMANCE = 0.50;
    private const LEARNING_HEALTH_WEIGHT_CONCEPT = 0.30;
    private const LEARNING_HEALTH_WEIGHT_CONSISTENCY = 0.20;

    private const LEARNING_HEALTH_MIN_ANALYSES = 2;

    public function __construct(
        private MongoAnalysisRepository $mongoAnalysisRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function getDashboard(string $studentId): AcademicIntelligenceResponseDTO
    {
        $analyses = $this->mongoAnalysisRepository->findCompletedByStudentId($studentId);

        $records = [];
        foreach ($analyses as $analysis) {
            if (!$analysis instanceof Analysis) {
                continue;
            }
            $record = $this->normalizeExamRecord($analysis);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        usort($records, fn(array $a, array $b) => $a['completedAt']->getTimestamp() <=> $b['completedAt']->getTimestamp());

        return new AcademicIntelligenceResponseDTO(
            studentId: $studentId,
            performance: $this->buildPerformance($records),
            learningHealth: $this->buildLearningHealth($records),
            weakestTopics: $this->buildWeakestTopics($records),
            todaysGoal: $this->buildTodaysGoal($records),
            aiCoachInsight: $this->buildAICoachInsight($records),
            subjectPerformance: $this->buildSubjectPerformance($records),
            upcomingTests: $this->buildUpcomingTests(),
            recentTest: $this->buildRecentTest($records),
            studyStreak: $this->buildStudyStreak($records),
        );
    }

    private function normalizeExamRecord(Analysis $analysis): ?array
    {
        $aiAnalysis = $analysis->getAiAnalysis();
        if (!$aiAnalysis || !is_array($aiAnalysis)) {
            return null;
        }

        $exam = $analysis->getExam();
        $processing = $analysis->getProcessing();

        $createdAt = $analysis->getCreatedAt();
        $completedAt = $processing->getCompletedAt() ?? $createdAt;
        $date = $completedAt->format('Y-m-d');

        $currentExamAnalysis = $aiAnalysis['currentExamAnalysis'] ?? null;
        $performance = [];
        if (is_array($currentExamAnalysis)) {
            $perf = $currentExamAnalysis['performance'] ?? [];
            $performance = [
                'score' => $this->validNumber($perf['score'] ?? null),
                'totalMarks' => $this->validNumber($perf['totalMarks'] ?? null),
                'percentage' => $this->validPercentage($perf['percentage'] ?? null),
            ];
        }

        $extractedMetadata = $exam->getExtractedMetadata() ?? [];
        $subjectFromMeta = '';
        if (isset($extractedMetadata['examSubjectName']['value'])) {
            $subjectFromMeta = (string) $extractedMetadata['examSubjectName']['value'];
        } elseif (isset($extractedMetadata['subject']['value'])) {
            $subjectFromMeta = (string) $extractedMetadata['subject']['value'];
        }

        $subject = (string) ($exam->getSubject() ?? $subjectFromMeta ?? '');

        return [
            'analysisId' => $analysis->getAnalysisId(),
            'subject' => $subject,
            'date' => $date,
            'createdAt' => $createdAt,
            'completedAt' => $completedAt,
            'performance' => $performance,
            'conceptAssessments' => is_array($currentExamAnalysis) ? ($currentExamAnalysis['conceptAssessments'] ?? []) : [],
            'weaknesses' => is_array($currentExamAnalysis) ? ($currentExamAnalysis['weaknesses'] ?? []) : [],
            'needsAttention' => is_array($currentExamAnalysis) ? ($currentExamAnalysis['needsAttention'] ?? []) : [],
            'conceptGaps' => is_array($currentExamAnalysis) ? ($currentExamAnalysis['conceptGaps'] ?? []) : [],
            'recommendations' => is_array($currentExamAnalysis) ? ($currentExamAnalysis['recommendations'] ?? []) : [],
            'mistakes' => is_array($currentExamAnalysis) ? ($currentExamAnalysis['mistakes'] ?? []) : [],
            'questionAnalysis' => $aiAnalysis['questionAnalysis'] ?? [],
            'strengths' => is_array($currentExamAnalysis) ? ($currentExamAnalysis['strengths'] ?? []) : [],
        ];
    }

    private function buildPerformance(array $records): DashboardPerformanceDTO
    {
        $totalAssessments = count($records);
        $assessmentsWithValidMarks = 0;
        $sumScored = 0;
        $sumTotal = 0;

        $trend = [];
        foreach ($records as $record) {
            $perf = $record['performance'];
            $score = $perf['score'] ?? null;
            $totalMarks = $perf['totalMarks'] ?? null;
            $percentage = $perf['percentage'] ?? null;

            $trend[] = new HistoricalPointDTO(
                analysisId: $record['analysisId'],
                date: $record['date'],
                subject: $record['subject'] ?: 'Unknown',
                score: $score,
                totalMarks: $totalMarks,
                percentage: $percentage,
            );

            if ($score !== null && $totalMarks !== null && $totalMarks > 0) {
                $assessmentsWithValidMarks++;
                $sumScored += $score;
                $sumTotal += $totalMarks;
            }
        }

        $overallScore = null;
        if ($assessmentsWithValidMarks > 0 && $sumTotal > 0) {
            $overallScore = round(($sumScored / $sumTotal) * 100, 2);
        }

        return new DashboardPerformanceDTO(
            overallScore: $overallScore,
            totalAssessments: $totalAssessments,
            assessmentsWithValidMarks: $assessmentsWithValidMarks,
            historicalTrend: $trend,
        );
    }

    private function buildLearningHealth(array $records): DashboardLearningHealthDTO
    {
        $formula = 'Learning Health = 0.50 × PerformanceScore + 0.30 × ConceptHealth + 0.20 × ConsistencyScore (0–100). '
            . 'Unavailable when fewer than ' . self::LEARNING_HEALTH_MIN_ANALYSES . ' completed analyses or no performance data.';

        if (count($records) < self::LEARNING_HEALTH_MIN_ANALYSES) {
            return new DashboardLearningHealthDTO(
                available: false,
                score: null,
                label: null,
                formula: $formula,
                components: [],
            );
        }

        $percentages = [];
        foreach ($records as $record) {
            $p = $record['performance']['percentage'] ?? null;
            if ($p !== null) {
                $percentages[] = $p;
            }
        }

        if (empty($percentages)) {
            return new DashboardLearningHealthDTO(
                available: false,
                score: null,
                label: null,
                formula: $formula,
                components: [],
            );
        }

        $performanceScore = round(array_sum($percentages) / count($percentages), 2);

        $conceptHealth = $this->computeConceptHealth($records);
        $consistencyScore = $this->computeConsistencyScore($percentages);

        $score = round(
            (self::LEARNING_HEALTH_WEIGHT_PERFORMANCE * $performanceScore)
            + (self::LEARNING_HEALTH_WEIGHT_CONCEPT * $conceptHealth)
            + (self::LEARNING_HEALTH_WEIGHT_CONSISTENCY * $consistencyScore),
            2
        );

        $label = $this->learningHealthLabel($score);

        return new DashboardLearningHealthDTO(
            available: true,
            score: $score,
            label: $label,
            formula: $formula,
            components: [
                'performanceScore' => $performanceScore,
                'performanceWeight' => self::LEARNING_HEALTH_WEIGHT_PERFORMANCE,
                'conceptHealth' => $conceptHealth,
                'conceptWeight' => self::LEARNING_HEALTH_WEIGHT_CONCEPT,
                'consistencyScore' => $consistencyScore,
                'consistencyWeight' => self::LEARNING_HEALTH_WEIGHT_CONSISTENCY,
            ],
        );
    }

    private function computeConceptHealth(array $records): float
    {
        $strong = 0;
        $known = 0;
        foreach ($records as $record) {
            foreach ($record['conceptAssessments'] as $assessment) {
                $status = strtoupper((string) ($assessment['status'] ?? ''));
                if ($status === '') {
                    continue;
                }
                $known++;
                if ($status === self::CONCEPT_STRONG) {
                    $strong++;
                }
            }
            foreach ($record['questionAnalysis'] as $q) {
                $concept = $q['concept'] ?? null;
                if (!is_array($concept)) {
                    continue;
                }
                $status = strtoupper((string) ($concept['status'] ?? ''));
                if ($status === '') {
                    continue;
                }
                $known++;
                if ($status === self::CONCEPT_STRONG) {
                    $strong++;
                }
            }
        }

        if ($known === 0) {
            return 50.0; // neutral when no concept evidence
        }

        return round(($strong / $known) * 100, 2);
    }

    private function computeConsistencyScore(array $percentages): float
    {
        if (count($percentages) < 2) {
            return 0.0;
        }

        $nonDeclining = 0;
        $transitions = count($percentages) - 1;
        for ($i = 1; $i < count($percentages); $i++) {
            if ($percentages[$i] >= $percentages[$i - 1] - 10) {
                $nonDeclining++;
            }
        }

        return round(($nonDeclining / $transitions) * 100, 2);
    }

    private function learningHealthLabel(?float $score): ?string
    {
        if ($score === null) {
            return null;
        }
        if ($score >= 80) {
            return 'EXCELLENT';
        }
        if ($score >= 60) {
            return 'GOOD';
        }
        if ($score >= 40) {
            return 'FAIR';
        }
        return 'NEEDS_ATTENTION';
    }

    private function buildWeakestTopics(array $records): array
    {
        $topics = [];
        $seenPerAnalysis = [];

        foreach ($records as $record) {
            $analysisId = $record['analysisId'];
            $subject = $record['subject'] ?: 'Unknown';

            $candidates = [];

            foreach ($record['conceptAssessments'] as $assessment) {
                $name = trim((string) ($assessment['concept'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $status = strtoupper((string) ($assessment['status'] ?? ''));
                if ($status === self::CONCEPT_STRONG || $status === self::CONCEPT_INSUFFICIENT) {
                    continue;
                }
                $candidates[$this->normalizeKey($name)] = [
                    'name' => $name,
                    'status' => $status,
                    'confidence' => $this->validNumber($assessment['confidence'] ?? null),
                ];
            }

            foreach ($record['questionAnalysis'] as $q) {
                $concept = $q['concept'] ?? null;
                if (!is_array($concept)) {
                    continue;
                }
                $name = trim((string) ($concept['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $status = strtoupper((string) ($concept['status'] ?? ''));
                if ($status === self::CONCEPT_STRONG || $status === self::CONCEPT_INSUFFICIENT) {
                    continue;
                }
                $key = $this->normalizeKey($name);
                if (!isset($candidates[$key])) {
                    $candidates[$key] = [
                        'name' => $name,
                        'status' => $status,
                        'confidence' => null,
                    ];
                }
            }

            foreach ($record['weaknesses'] as $weakness) {
                $name = trim((string) $weakness);
                if ($name === '') {
                    continue;
                }
                if (!self::isValidTopicCandidate($name)) {
                    continue;
                }
                $key = $this->normalizeKey($name);
                if (!isset($candidates[$key])) {
                    $candidates[$key] = ['name' => $name, 'status' => self::CONCEPT_NEEDS_ATTENTION, 'confidence' => null];
                }
            }

            foreach ($record['needsAttention'] as $attention) {
                $name = trim((string) $attention);
                if ($name === '') {
                    continue;
                }
                if (!self::isValidTopicCandidate($name)) {
                    continue;
                }
                $key = $this->normalizeKey($name);
                if (!isset($candidates[$key])) {
                    $candidates[$key] = ['name' => $name, 'status' => self::CONCEPT_NEEDS_ATTENTION, 'confidence' => null];
                }
            }

            foreach ($record['conceptGaps'] as $gap) {
                $name = trim((string) $gap);
                if ($name === '') {
                    continue;
                }
                if (!self::isValidTopicCandidate($name)) {
                    continue;
                }
                $key = $this->normalizeKey($name);
                if (!isset($candidates[$key])) {
                    $candidates[$key] = ['name' => $name, 'status' => self::CONCEPT_NEEDS_ATTENTION, 'confidence' => null];
                }
            }

            foreach ($record['mistakes'] as $mistake) {
                $name = trim((string) ($mistake['description'] ?? ''));
                if ($name === '') {
                    continue;
                }
                if (!self::isValidTopicCandidate($name)) {
                    continue;
                }
                $key = $this->normalizeKey($name);
                if (!isset($candidates[$key])) {
                    $candidates[$key] = ['name' => $name, 'status' => self::CONCEPT_NEEDS_ATTENTION, 'confidence' => $this->validNumber($mistake['confidence'] ?? null)];
                }
            }

            foreach ($candidates as $key => $candidate) {
                if (!isset($topics[$key])) {
                    $topics[$key] = [
                        'topic' => $candidate['name'],
                        'subject' => $subject,
                        'evidenceCount' => 0,
                        'recurring' => false,
                        'latestStatus' => null,
                        'latestDate' => null,
                        'supportingAnalyses' => [],
                    ];
                }

                if (isset($seenPerAnalysis[$analysisId][$key])) {
                    continue;
                }
                $seenPerAnalysis[$analysisId][$key] = true;

                $topics[$key]['evidenceCount']++;
                $topics[$key]['supportingAnalyses'][] = $analysisId;
                if ($topics[$key]['latestDate'] === null || $record['date'] > $topics[$key]['latestDate']) {
                    $topics[$key]['latestDate'] = $record['date'];
                    $topics[$key]['latestStatus'] = $candidate['status'];
                }
            }
        }

        foreach ($topics as $key => &$topic) {
            $topic['recurring'] = $topic['evidenceCount'] >= 2;
            unset($topic['latestDate']);
        }
        unset($topic);

        uasort($topics, fn(array $a, array $b) => $b['evidenceCount'] <=> $a['evidenceCount']);

        $result = [];
        foreach ($topics as $topic) {
            $result[] = new DashboardWeakestTopicDTO(
                topic: $topic['topic'],
                subject: $topic['subject'],
                evidenceCount: $topic['evidenceCount'],
                recurring: $topic['recurring'],
                latestStatus: $topic['latestStatus'],
                supportingAnalyses: $topic['supportingAnalyses'],
            );
        }

        return $result;
    }

    private function buildTodaysGoal(array $records): DashboardTodaysGoalDTO
    {
        $weakestTopics = $this->buildWeakestTopics($records);

        $recurring = null;
        foreach ($weakestTopics as $topic) {
            if ($topic->evidenceCount >= 2) {
                $recurring = $topic;
                break;
            }
        }

        if ($recurring !== null) {
            return new DashboardTodaysGoalDTO(
                action: 'Review and practice ' . $recurring->topic,
                topic: $recurring->topic,
                subject: $recurring->subject,
                reason: 'Recurring difficulty across ' . $recurring->evidenceCount . ' completed assessments.',
                durationMinutes: 30,
                type: 'SUGGESTED',
            );
        }

        $single = null;
        foreach ($weakestTopics as $topic) {
            if ($topic->evidenceCount >= 1) {
                $single = $topic;
                break;
            }
        }

        if ($single !== null) {
            return new DashboardTodaysGoalDTO(
                action: 'Review ' . $single->topic,
                topic: $single->topic,
                subject: $single->subject,
                reason: 'Weakness detected in a recent assessment; not yet established as recurring.',
                durationMinutes: null,
                type: 'SUGGESTED',
            );
        }

        $latest = end($records);
        if ($latest !== false) {
            $subject = $latest['subject'] ?: 'Unknown';
            $percentage = $latest['performance']['percentage'] ?? null;
            $reason = $percentage !== null
                ? 'Recent assessment completed at ' . $percentage . '%; maintain current practice.'
                : 'No specific weakness detected; maintain current practice.';
            return new DashboardTodaysGoalDTO(
                action: 'Continue regular practice',
                topic: 'General review',
                subject: $subject,
                reason: $reason,
                durationMinutes: null,
                type: 'SUGGESTED',
            );
        }

        return new DashboardTodaysGoalDTO(
            action: 'Complete an assessment to receive recommendations',
            topic: 'General',
            subject: 'General',
            reason: 'No completed analyses available to base a goal on.',
            durationMinutes: null,
            type: 'SUGGESTED',
        );
    }

    private function buildAICoachInsight(array $records): DashboardAICoachInsightDTO
    {
        if (count($records) < 2) {
            return new DashboardAICoachInsightDTO(
                type: 'INSUFFICIENT_DATA',
                title: 'Not enough data for coaching insight',
                description: 'Complete at least two assessments to receive a personalized insight.',
                subject: 'General',
                confidence: null,
                severity: 'NEUTRAL',
                evidence: [],
            );
        }

        $subjects = [];
        foreach ($records as $record) {
            $subject = $record['subject'] ?: 'Unknown';
            $subjects[$subject] = $subjects[$subject] ?? [];
            $subjects[$subject][] = $record;
        }

        $bestInsight = null;
        $bestConfidence = 0.0;

        foreach ($subjects as $subjectName => $subjectRecords) {
            $percentages = [];
            foreach ($subjectRecords as $r) {
                $p = $r['performance']['percentage'] ?? null;
                if ($p !== null) {
                    $percentages[] = $p;
                }
            }
            if (count($percentages) < 2) {
                continue;
            }

            $first = $percentages[0];
            $last = $percentages[count($percentages) - 1];
            $diff = round($last - $first, 2);

            $confidence = min(0.95, 0.5 + (count($percentages) * 0.1));

            if ($diff > 5) {
                $candidateConfidence = $confidence;
                if ($candidateConfidence > $bestConfidence) {
                    $bestConfidence = $candidateConfidence;
                    $bestInsight = new DashboardAICoachInsightDTO(
                        type: 'IMPROVEMENT',
                        title: $subjectName . ' is improving',
                        description: sprintf(
                            'Your %s score improved from %s%% to %s%% across %d completed assessments.',
                            $subjectName,
                            $first,
                            $last,
                            count($percentages)
                        ),
                        subject: $subjectName,
                        confidence: round($candidateConfidence, 2),
                        severity: 'POSITIVE',
                        evidence: array_map(fn($r) => $r['analysisId'], $subjectRecords),
                    );
                }
            } elseif ($diff < -5) {
                $candidateConfidence = $confidence;
                if ($candidateConfidence > $bestConfidence) {
                    $bestConfidence = $candidateConfidence;
                    $bestInsight = new DashboardAICoachInsightDTO(
                        type: 'DECLINE',
                        title: $subjectName . ' needs attention',
                        description: sprintf(
                            'Your %s score declined from %s%% to %s%% across %d completed assessments.',
                            $subjectName,
                            $first,
                            $last,
                            count($percentages)
                        ),
                        subject: $subjectName,
                        confidence: round($candidateConfidence, 2),
                        severity: 'NEGATIVE',
                        evidence: array_map(fn($r) => $r['analysisId'], $subjectRecords),
                    );
                }
            }
        }

        if ($bestInsight !== null) {
            return $bestInsight;
        }

        $weakestTopics = $this->buildWeakestTopics($records);
        foreach ($weakestTopics as $topic) {
            if ($topic->recurring) {
                return new DashboardAICoachInsightDTO(
                    type: 'CONCEPT_NEEDS_ATTENTION',
                    title: $topic->topic . ' needs attention',
                    description: $topic->topic . ' has appeared as a weakness in ' . $topic->evidenceCount . ' assessments.',
                    subject: $topic->subject,
                    confidence: min(0.9, 0.5 + ($topic->evidenceCount * 0.15)),
                    severity: 'NEGATIVE',
                    evidence: $topic->supportingAnalyses,
                );
            }
        }

        $strengths = [];
        foreach ($records as $record) {
            foreach ($record['strengths'] as $strength) {
                $name = trim((string) $strength);
                if ($name !== '') {
                    $strengths[] = ['name' => $name, 'subject' => $record['subject'] ?: 'Unknown', 'analysisId' => $record['analysisId']];
                }
            }
        }
        if (!empty($strengths)) {
            $top = $strengths[0];
            return new DashboardAICoachInsightDTO(
                type: 'STRENGTH',
                title: 'Key strength: ' . $top['name'],
                description: 'Your most consistent strength is ' . $top['name'] . ' (' . $top['subject'] . ').',
                subject: $top['subject'],
                confidence: 0.6,
                severity: 'POSITIVE',
                evidence: [$top['analysisId']],
            );
        }

        return new DashboardAICoachInsightDTO(
            type: 'STABLE',
            title: 'Performance is stable',
            description: 'Your assessment results are consistent across completed assessments.',
            subject: 'General',
            confidence: 0.5,
            severity: 'NEUTRAL',
            evidence: array_map(fn($r) => $r['analysisId'], $records),
        );
    }

    private function buildSubjectPerformance(array $records): array
    {
        $subjects = [];
        foreach ($records as $record) {
            $subject = $record['subject'] ?: 'Unknown';
            $subjects[$subject] = $subjects[$subject] ?? [];
            $subjects[$subject][] = $record;
        }

        $result = [];
        foreach ($subjects as $subjectName => $subjectRecords) {
            $percentages = [];
            $scores = [];
            $totalQuestions = 0;
            $totalCorrect = 0;
            $totalIncorrect = 0;
            $strongConcepts = 0;
            $developingConcepts = 0;
            $needsAttentionConcepts = 0;

            foreach ($subjectRecords as $record) {
                $perf = $record['performance'];
                if (($perf['percentage'] ?? null) !== null) {
                    $percentages[] = $perf['percentage'];
                }
                if (($perf['score'] ?? null) !== null) {
                    $scores[] = $perf['score'];
                }

                foreach ($record['questionAnalysis'] as $q) {
                    $eval = $q['evaluation'] ?? [];
                    $evalStatus = strtoupper((string) ($eval['evaluationStatus'] ?? ''));
                    if ($evalStatus === self::EVAL_CORRECT) {
                        $totalCorrect++;
                    } elseif ($evalStatus === self::EVAL_INCORRECT) {
                        $totalIncorrect++;
                    }
                    $totalQuestions++;

                    $concept = $q['concept'] ?? null;
                    if (is_array($concept)) {
                        $status = strtoupper((string) ($concept['status'] ?? ''));
                        if ($status === self::CONCEPT_STRONG) {
                            $strongConcepts++;
                        } elseif ($status === self::CONCEPT_DEVELOPING) {
                            $developingConcepts++;
                        } elseif ($status === self::CONCEPT_NEEDS_ATTENTION) {
                            $needsAttentionConcepts++;
                        }
                    }
                }

                foreach ($record['conceptAssessments'] as $assessment) {
                    $status = strtoupper((string) ($assessment['status'] ?? ''));
                    if ($status === self::CONCEPT_STRONG) {
                        $strongConcepts++;
                    } elseif ($status === self::CONCEPT_DEVELOPING) {
                        $developingConcepts++;
                    } elseif ($status === self::CONCEPT_NEEDS_ATTENTION) {
                        $needsAttentionConcepts++;
                    }
                }
            }

            $average = !empty($percentages) ? round(array_sum($percentages) / count($percentages), 2) : null;
            $latest = !empty($percentages) ? end($percentages) : null;
            $highest = !empty($percentages) ? max($percentages) : null;
            $lowest = !empty($percentages) ? min($percentages) : null;
            $trend = $this->determineSubjectTrend($percentages);

            $conceptSummary = [
                'strong' => $strongConcepts,
                'developing' => $developingConcepts,
                'needsAttention' => $needsAttentionConcepts,
            ];

            $result[] = new DashboardSubjectPerformanceDTO(
                subject: $subjectName,
                exams: count($subjectRecords),
                average: $average,
                latest: $latest,
                highest: $highest,
                lowest: $lowest,
                trend: $trend,
                totalQuestions: $totalQuestions,
                correct: $totalCorrect,
                incorrect: $totalIncorrect,
                conceptSummary: $conceptSummary,
            );
        }

        usort($result, fn($a, $b) => strcasecmp($a->subject, $b->subject));

        return $result;
    }

    private function determineSubjectTrend(array $percentages): string
    {
        if (count($percentages) < 2) {
            return count($percentages) === 1 ? self::TREND_NEW : self::TREND_INSUFFICIENT;
        }

        $first = $percentages[0];
        $last = $percentages[count($percentages) - 1];

        if ($last > $first) {
            return self::TREND_IMPROVING;
        }
        if ($last < $first) {
            return self::TREND_DECLINING;
        }
        return self::TREND_STABLE;
    }

    private function buildUpcomingTests(): array
    {
        return [];
    }

    private function buildRecentTest(array $records): DashboardRecentTestDTO
    {
        if (empty($records)) {
            return new DashboardRecentTestDTO(
                analysisId: null,
                subject: null,
                date: null,
                score: null,
                totalMarks: null,
                percentage: null,
            );
        }

        $latest = end($records);
        $perf = $latest['performance'];
        return new DashboardRecentTestDTO(
            analysisId: $latest['analysisId'],
            subject: $latest['subject'] ?: null,
            date: $latest['date'],
            score: $perf['score'] ?? null,
            totalMarks: $perf['totalMarks'] ?? null,
            percentage: $perf['percentage'] ?? null,
        );
    }

    private function buildStudyStreak(array $records): DashboardStudyStreakDTO
    {
        if (empty($records)) {
            return new DashboardStudyStreakDTO(
                currentStreak: 0,
                lastActivityDate: null,
                available: false,
            );
        }

        $dates = [];
        foreach ($records as $record) {
            $dates[$record['date']] = true;
        }
        $uniqueDates = array_keys($dates);
        rsort($uniqueDates);

        $currentStreak = 0;
        $prevDate = null;
        foreach ($uniqueDates as $date) {
            if ($prevDate === null) {
                $currentStreak = 1;
            } else {
                $prev = new \DateTimeImmutable($prevDate);
                $curr = new \DateTimeImmutable($date);
                $diff = $curr->diff($prev)->days;
                if ($diff === 1) {
                    $currentStreak++;
                } else {
                    break;
                }
            }
            $prevDate = $date;
        }

        return new DashboardStudyStreakDTO(
            currentStreak: $currentStreak,
            lastActivityDate: $uniqueDates[0],
            available: true,
        );
    }

    private function normalizeKey(string $value): string
    {
        return strtolower(trim($value));
    }

    /**
     * Determines whether a candidate topic name is a concise, single-concept
     * topic title rather than a long assessment-level narrative summary.
     *
     * Valid topic names (e.g. "Min-max normalization", "SVM decision boundaries")
     * are short, contain no internal sentence punctuation, and do not reference
     * question numbers or assessment-level conclusions.
     *
     * Invalid candidates are narrative paragraphs such as:
     *   "THE VISIBLE ANSWERS DO NOT DEMONSTRATE UNDERSTANDING OF MIN-MAX
     *    NORMALIZATION, THE DEFINITION AND PURPOSE OF AN SVM DECISION BOUNDARY,
     *    OR THE DISTINCTION BETWEEN MODEL PARAMETERS AND HYPERPARAMETERS;
     *    QUESTIONS 30, 33, AND 35 ARE UNANSWERED."
     */
    private static function isValidTopicCandidate(string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }

        // Reject overly long summaries (more than 60 chars or 8 words)
        $wordCount = count(preg_split('/\s+/', $name));
        if (strlen($name) > 60 || $wordCount > 8) {
            return false;
        }

        // Reject candidates containing internal sentence punctuation or semicolons
        if (preg_match('/[.!?]\s/', $name) || str_contains($name, ';')) {
            return false;
        }

        // Reject candidates referencing specific question numbers
        if (preg_match('/\bquestion[s]?\s+\d+/i', $name)) {
            return false;
        }

        // Reject candidates starting with assessment-level narrative phrases
        $lower = strtolower($name);
        $narrativePrefixes = [
            'the visible answers',
            'the student',
            'this assessment',
            'these answers',
            'the answers',
            'assessment-wide',
            'overall performance',
        ];
        foreach ($narrativePrefixes as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private function validNumber(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $intValue = (int) $value;
        if ($intValue < 0) {
            return null;
        }
        return $intValue;
    }

    private function validPercentage(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $floatValue = (float) $value;
        if ($floatValue < 0 || $floatValue > 100) {
            return null;
        }
        return $floatValue;
    }
}
