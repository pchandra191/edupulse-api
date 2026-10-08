<?php

declare(strict_types=1);

namespace App\Service\StudentIntelligence;

use App\Document\Analysis;
use App\Document\Exam;
use App\Document\Processing;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

readonly class StudentIntelligenceService
{
    private const STATUS_DONE = 'DONE';
    private const STATUS_STRONG = 'STRONG';
    private const STATUS_DEVELOPING = 'DEVELOPING';
    private const STATUS_NEEDS_ATTENTION = 'NEEDS_ATTENTION';
    private const STATUS_INSUFFICIENT = 'INSUFFICIENT_EVIDENCE';
    private const EVAL_CORRECT = 'CORRECT';
    private const EVAL_PARTIALLY_CORRECT = 'PARTIALLY_CORRECT';
    private const EVAL_INCORRECT = 'INCORRECT';
    private const EVAL_UNANSWERED = 'UNANSWERED';
    private const TREND_IMPROVING = 'IMPROVING';
    private const TREND_DECLINING = 'DECLINING';
    private const TREND_STABLE = 'STABLE';
    private const TREND_NEW = 'NEW';
    private const TREND_NEEDS_ATTENTION = 'NEEDS_ATTENTION';
    private const TREND_INSUFFICIENT = 'INSUFFICIENT_DATA';
    private const DIRECT_SOURCE = 'OPENAI_DIRECT_ANALYSIS';

    public function __construct(
        private MongoAnalysisRepository $mongoAnalysisRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function getIntelligence(string $studentId): array
    {
        $analyses = $this->mongoAnalysisRepository->findCompletedByStudentId($studentId);

        if (empty($analyses)) {
            return $this->buildEmptyIntelligence();
        }

        $examRecords = [];
        foreach ($analyses as $analysis) {
            $record = $this->normalizeExamRecord($analysis);
            if ($record !== null) {
                $examRecords[] = $record;
            }
        }

        usort($examRecords, fn(array $a, array $b) => $a['date'] <=> $b['date']);

        if (empty($examRecords)) {
            return $this->buildEmptyIntelligence();
        }

        return [
            'overview' => $this->buildOverview($examRecords),
            'performance' => $this->buildPerformance($examRecords),
            'trends' => $this->buildTrends($examRecords),
            'subjects' => $this->buildSubjects($examRecords),
            'concepts' => $this->buildConcepts($examRecords),
            'strengths' => $this->buildStrengths($examRecords),
            'weaknesses' => $this->buildWeaknesses($examRecords),
            'learningPlan' => $this->buildLearningPlan($examRecords),
            'recommendations' => $this->buildRecommendations($examRecords),
            'doubtContext' => $this->buildDoubtContext($examRecords),
            'insights' => $this->buildInsights($examRecords),
        ];
    }

    private function normalizeExamRecord(Analysis $analysis): ?array
    {
        $aiAnalysis = $analysis->getAiAnalysis();
        if (!$aiAnalysis || !is_array($aiAnalysis)) {
            return null;
        }

        $exam = $analysis->getExam();
        $processing = $analysis->getProcessing();

        $subject = $exam->getSubject() ?? '';
        $date = $processing->getCompletedAt()?->format('Y-m-d')
            ?? $analysis->getCreatedAt()->format('Y-m-d');

        $record = [
            'analysisId' => $analysis->getAnalysisId(),
            'subject' => $subject,
            'date' => $date,
            'createdAt' => $analysis->getCreatedAt(),
            'aiAnalysis' => $aiAnalysis,
            'exam' => [
                'title' => $exam->getTitle(),
                'subject' => $exam->getSubject(),
                'totalMarks' => $exam->getTotalMarks(),
                'questionCount' => $exam->getQuestionCount(),
            ],
        ];

        $currentExamAnalysis = $aiAnalysis['currentExamAnalysis'] ?? null;
        if (is_array($currentExamAnalysis)) {
            $performance = $currentExamAnalysis['performance'] ?? [];
            $record['performance'] = [
                'score' => $performance['score'] ?? null,
                'totalMarks' => $performance['totalMarks'] ?? null,
                'percentage' => $performance['percentage'] ?? null,
            ];
            $record['strengths'] = $currentExamAnalysis['strengths'] ?? [];
            $record['weaknesses'] = $currentExamAnalysis['weaknesses'] ?? [];
            $record['needsAttention'] = $currentExamAnalysis['needsAttention'] ?? [];
            $record['conceptGaps'] = $currentExamAnalysis['conceptGaps'] ?? [];
            $record['recommendations'] = $currentExamAnalysis['recommendations'] ?? [];
            $record['conceptAssessments'] = $currentExamAnalysis['conceptAssessments'] ?? [];
            $record['mistakes'] = $currentExamAnalysis['mistakes'] ?? [];
            $record['evidence'] = $currentExamAnalysis['evidence'] ?? [];
            $record['questionAnalysis'] = $aiAnalysis['questionAnalysis'] ?? [];
            $record['mode'] = 'direct';
        } elseif (isset($aiAnalysis['summary']) && is_array($aiAnalysis['summary'])) {
            $summary = $aiAnalysis['summary'];
            $record['performance'] = [
                'score' => $summary['marksAwarded'] ?? null,
                'totalMarks' => $summary['totalMarks'] ?? null,
                'percentage' => $summary['percentage'] ?? null,
            ];
            $record['strengths'] = [];
            $record['weaknesses'] = [];
            $record['needsAttention'] = [];
            $record['conceptGaps'] = [];
            $record['recommendations'] = $aiAnalysis['recommendations'] ?? [];
            $record['conceptAssessments'] = [];
            $record['mistakes'] = [];
            $record['evidence'] = [];
            $record['questionAnalysis'] = $aiAnalysis['questions'] ?? [];
            $record['mode'] = 'legacy';
            $record['summary'] = $summary;
        } else {
            return null;
        }

        return $record;
    }

    private function buildEmptyIntelligence(): array
    {
        return [
            'overview' => [
                'totalCompletedExams' => 0,
                'totalSubjects' => 0,
                'totalQuestions' => 0,
                'totalAttempted' => 0,
                'totalCorrect' => 0,
                'totalIncorrect' => 0,
                'overallAverage' => 0,
                'latestScore' => null,
                'bestScore' => null,
                'lowestScore' => null,
                'improvementFromPrevious' => null,
            ],
            'performance' => ['trend' => []],
            'trends' => [
                'overall' => self::TREND_INSUFFICIENT,
                'subjects' => [],
                'concepts' => [],
            ],
            'subjects' => [],
            'concepts' => [],
            'strengths' => [],
            'weaknesses' => [],
            'learningPlan' => [
                'priorities' => [],
                'maintain' => [],
                'recentRecommendations' => [],
            ],
            'recommendations' => [],
            'doubtContext' => [
                'strongestSubjects' => [],
                'weakestSubjects' => [],
                'decliningSubjects' => [],
                'improvingSubjects' => [],
                'weakConcepts' => [],
                'repeatedMistakes' => [],
                'recentExam' => null,
                'recentMistakes' => [],
                'recommendedTopics' => [],
            ],
            'insights' => [],
        ];
    }

    // ============================================================
    // OVERVIEW
    // ============================================================
    private function buildOverview(array $examRecords): array
    {
        $totalCompletedExams = count($examRecords);
        $subjects = [];
        $totalQuestions = 0;
        $totalAttempted = 0;
        $totalCorrect = 0;
        $totalIncorrect = 0;
        $percentages = [];
        $scores = [];
        $latestScore = null;
        $bestScore = null;
        $lowestScore = null;

        foreach ($examRecords as $record) {
            $subject = $record['subject'] ?: 'Unknown';
            $subjects[$subject] = true;

            $questionCount = $this->resolveQuestionCount($record);
            $totalQuestions += $questionCount;

            $perf = $record['performance'];
            $percentage = $perf['percentage'] ?? null;
            $score = $perf['score'] ?? null;

            if ($percentage !== null) {
                $percentages[] = $percentage;
            }
            if ($score !== null) {
                $scores[] = $score;
            }

            if ($record['mode'] === 'direct') {
                $qa = $record['questionAnalysis'] ?? [];
                foreach ($qa as $q) {
                    $evalStatus = strtoupper((string) ($q['evaluation']['evaluationStatus'] ?? ''));
                    if (in_array($evalStatus, [self::EVAL_CORRECT, self::EVAL_PARTIALLY_CORRECT, self::EVAL_INCORRECT], true)) {
                        $totalAttempted++;
                    }
                    if ($evalStatus === self::EVAL_CORRECT) {
                        $totalCorrect++;
                    }
                    if ($evalStatus === self::EVAL_INCORRECT) {
                        $totalIncorrect++;
                    }
                }
            } elseif ($record['mode'] === 'legacy' && isset($record['summary'])) {
                $summary = $record['summary'];
                $totalAttempted += $summary['attempted'] ?? 0;
                $totalCorrect += $summary['correct'] ?? 0;
                $totalIncorrect += $summary['incorrect'] ?? 0;
            }
        }

        if (!empty($scores)) {
            $latestScore = end($scores);
            $bestScore = max($scores);
            $lowestScore = min($scores);
        }

        $overallAverage = !empty($percentages) ? round(array_sum($percentages) / count($percentages), 2) : 0;
        $improvementFromPrevious = $this->calculateImprovementFromPrevious($examRecords);

        return [
            'totalCompletedExams' => $totalCompletedExams,
            'totalSubjects' => count($subjects),
            'totalQuestions' => $totalQuestions,
            'totalAttempted' => $totalAttempted,
            'totalCorrect' => $totalCorrect,
            'totalIncorrect' => $totalIncorrect,
            'overallAverage' => $overallAverage,
            'latestScore' => $latestScore,
            'bestScore' => $bestScore,
            'lowestScore' => $lowestScore,
            'improvementFromPrevious' => $improvementFromPrevious,
        ];
    }

    // ============================================================
    // PERFORMANCE
    // ============================================================
    private function buildPerformance(array $examRecords): array
    {
        $trend = [];
        foreach ($examRecords as $record) {
            $perf = $record['performance'];
            $trend[] = [
                'analysisId' => $record['analysisId'],
                'date' => $record['date'],
                'subject' => $record['subject'] ?: 'Unknown',
                'score' => $perf['score'] ?? null,
                'percentage' => $perf['percentage'] ?? null,
            ];
        }

        return ['trend' => $trend];
    }

    // ============================================================
    // TRENDS
    // ============================================================
    private function buildTrends(array $examRecords): array
    {
        $subjectTrends = [];
        $conceptTrends = [];

        $subjects = [];
        foreach ($examRecords as $record) {
            $subject = $record['subject'] ?: 'Unknown';
            $subjects[$subject] = $subjects[$subject] ?? [];
            $subjects[$subject][] = $record;
        }

        foreach ($subjects as $subject => $records) {
            $subjectTrends[] = [
                'subject' => $subject,
                'trend' => $this->determineSubjectTrend($records),
                'exams' => count($records),
            ];
        }

        $concepts = $this->aggregateConcepts($examRecords);
        foreach ($concepts as $conceptName => $conceptData) {
            $conceptTrends[] = [
                'concept' => $conceptName,
                'subject' => $conceptData['subject'],
                'trend' => $conceptData['trend'],
            ];
        }

        $overallTrend = self::TREND_INSUFFICIENT;
        if (count($examRecords) >= 2) {
            $first = $examRecords[0]['performance']['percentage'] ?? null;
            $last = $examRecords[count($examRecords) - 1]['performance']['percentage'] ?? null;
            if ($first !== null && $last !== null) {
                if ($last > $first) {
                    $overallTrend = self::TREND_IMPROVING;
                } elseif ($last < $first) {
                    $overallTrend = self::TREND_DECLINING;
                } else {
                    $overallTrend = self::TREND_STABLE;
                }
            }
        } elseif (count($examRecords) === 1) {
            $overallTrend = self::TREND_NEW;
        }

        return [
            'overall' => $overallTrend,
            'subjects' => $subjectTrends,
            'concepts' => $conceptTrends,
        ];
    }

    // ============================================================
    // SUBJECTS
    // ============================================================
    private function buildSubjects(array $examRecords): array
    {
        $subjects = [];
        foreach ($examRecords as $record) {
            $subject = $record['subject'] ?: 'Unknown';
            $subjects[$subject] = $subjects[$subject] ?? [];
            $subjects[$subject][] = $record;
        }

        $result = [];
        foreach ($subjects as $subjectName => $records) {
            $percentages = [];
            $scores = [];
            $strongConcepts = 0;
            $developingConcepts = 0;
            $needsAttentionConcepts = 0;
            $totalQuestions = 0;
            $totalCorrect = 0;
            $totalIncorrect = 0;

            foreach ($records as $record) {
                $perf = $record['performance'];
                if ($perf['percentage'] !== null) {
                    $percentages[] = $perf['percentage'];
                }
                if ($perf['score'] !== null) {
                    $scores[] = $perf['score'];
                }

                $totalQuestions += $this->resolveQuestionCount($record);

                if ($record['mode'] === 'direct') {
                    $qa = $record['questionAnalysis'] ?? [];
                    foreach ($qa as $q) {
                        $evalStatus = strtoupper((string) ($q['evaluation']['evaluationStatus'] ?? ''));
                        if ($evalStatus === self::EVAL_CORRECT) {
                            $totalCorrect++;
                        }
                        if ($evalStatus === self::EVAL_INCORRECT) {
                            $totalIncorrect++;
                        }
                    }
                } elseif ($record['mode'] === 'legacy' && isset($record['summary'])) {
                    $totalCorrect += $record['summary']['correct'] ?? 0;
                    $totalIncorrect += $record['summary']['incorrect'] ?? 0;
                }

                $assessments = $record['conceptAssessments'] ?? [];
                foreach ($assessments as $assessment) {
                    $status = strtoupper((string) ($assessment['status'] ?? ''));
                    if ($status === self::STATUS_STRONG) {
                        $strongConcepts++;
                    } elseif ($status === self::STATUS_DEVELOPING) {
                        $developingConcepts++;
                    } elseif ($status === self::STATUS_NEEDS_ATTENTION) {
                        $needsAttentionConcepts++;
                    }
                }

                $qa = $record['questionAnalysis'] ?? [];
                foreach ($qa as $q) {
                    $concept = $q['concept'] ?? null;
                    if (is_array($concept)) {
                        $status = strtoupper((string) ($concept['status'] ?? ''));
                        if ($status === self::STATUS_STRONG) {
                            $strongConcepts++;
                        } elseif ($status === self::STATUS_DEVELOPING) {
                            $developingConcepts++;
                        } elseif ($status === self::STATUS_NEEDS_ATTENTION) {
                            $needsAttentionConcepts++;
                        }
                    }
                }
            }

            $latest = !empty($percentages) ? end($percentages) : null;
            $highest = !empty($percentages) ? max($percentages) : null;
            $lowest = !empty($percentages) ? min($percentages) : null;
            $average = !empty($percentages) ? round(array_sum($percentages) / count($percentages), 2) : 0;
            $trend = $this->calculateNumericTrend($percentages);

            $result[] = [
                'subject' => $subjectName,
                'exams' => count($records),
                'average' => $average,
                'latest' => $latest,
                'highest' => $highest,
                'lowest' => $lowest,
                'trend' => $trend,
                'totalQuestions' => $totalQuestions,
                'correct' => $totalCorrect,
                'incorrect' => $totalIncorrect,
                'strongConcepts' => $strongConcepts,
                'developingConcepts' => $developingConcepts,
                'needsAttentionConcepts' => $needsAttentionConcepts,
            ];
        }

        return $result;
    }

    // ============================================================
    // CONCEPTS
    // ============================================================
    private function buildConcepts(array $examRecords): array
    {
        $aggregated = $this->aggregateConcepts($examRecords);
        $result = [];

        foreach ($aggregated as $conceptName => $conceptData) {
            $result[] = [
                'concept' => $conceptName,
                'subject' => $conceptData['subject'],
                'latestStatus' => $conceptData['latestStatus'],
                'latestConfidence' => $conceptData['latestConfidence'],
                'previousStatus' => $conceptData['previousStatus'],
                'previousConfidence' => $conceptData['previousConfidence'],
                'trend' => $conceptData['trend'],
                'attempts' => $conceptData['attempts'],
                'strongCount' => $conceptData['strongCount'],
                'developingCount' => $conceptData['developingCount'],
                'needsAttentionCount' => $conceptData['needsAttentionCount'],
                'firstSeen' => $conceptData['firstSeen'],
                'lastSeen' => $conceptData['lastSeen'],
                'history' => $conceptData['history'],
            ];
        }

        return $result;
    }

    private function aggregateConcepts(array $examRecords): array
    {
        $concepts = [];

        foreach ($examRecords as $record) {
            $subject = $record['subject'] ?: 'Unknown';
            $date = $record['date'];
            $assessments = $record['conceptAssessments'] ?? [];
            $qa = $record['questionAnalysis'] ?? [];

            foreach ($assessments as $assessment) {
                $name = (string) ($assessment['concept'] ?? '');
                if ($name === '') {
                    continue;
                }

                $status = strtoupper((string) ($assessment['status'] ?? self::STATUS_INSUFFICIENT));
                $confidence = isset($assessment['confidence']) ? (float) $assessment['confidence'] : null;

                if (!isset($concepts[$name])) {
                    $concepts[$name] = [
                        'subject' => $subject,
                        'history' => [],
                        'strongCount' => 0,
                        'developingCount' => 0,
                        'needsAttentionCount' => 0,
                        'attempts' => 0,
                        'firstSeen' => $date,
                        'lastSeen' => $date,
                    ];
                }

                $concepts[$name]['history'][] = [
                    'analysisId' => $record['analysisId'],
                    'date' => $date,
                    'status' => $status,
                    'confidence' => $confidence,
                ];
                $concepts[$name]['lastSeen'] = $date;
                $concepts[$name]['attempts']++;

                if ($status === self::STATUS_STRONG) {
                    $concepts[$name]['strongCount']++;
                } elseif ($status === self::STATUS_DEVELOPING) {
                    $concepts[$name]['developingCount']++;
                } elseif ($status === self::STATUS_NEEDS_ATTENTION) {
                    $concepts[$name]['needsAttentionCount']++;
                }
            }

            foreach ($qa as $q) {
                $concept = $q['concept'] ?? null;
                if (!is_array($concept)) {
                    continue;
                }
                $name = (string) ($concept['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $status = strtoupper((string) ($concept['status'] ?? self::STATUS_INSUFFICIENT));

                if (!isset($concepts[$name])) {
                    $concepts[$name] = [
                        'subject' => $subject,
                        'history' => [],
                        'strongCount' => 0,
                        'developingCount' => 0,
                        'needsAttentionCount' => 0,
                        'attempts' => 0,
                        'firstSeen' => $date,
                        'lastSeen' => $date,
                    ];
                }

                $concepts[$name]['history'][] = [
                    'analysisId' => $record['analysisId'],
                    'date' => $date,
                    'status' => $status,
                    'confidence' => null,
                ];
                $concepts[$name]['lastSeen'] = $date;
                $concepts[$name]['attempts']++;

                if ($status === self::STATUS_STRONG) {
                    $concepts[$name]['strongCount']++;
                } elseif ($status === self::STATUS_DEVELOPING) {
                    $concepts[$name]['developingCount']++;
                } elseif ($status === self::STATUS_NEEDS_ATTENTION) {
                    $concepts[$name]['needsAttentionCount']++;
                }
            }
        }

        foreach ($concepts as $name => $data) {
            $history = $data['history'];
            $latest = end($history);
            $previous = count($history) >= 2 ? $history[count($history) - 2] : null;

            $latestStatus = $latest['status'] ?? self::STATUS_INSUFFICIENT;
            $previousStatus = $previous['status'] ?? null;
            $latestConfidence = $latest['confidence'] ?? null;
            $previousConfidence = $previous['confidence'] ?? null;

            $trend = $this->determineConceptTrend($history);

            $concepts[$name] = array_merge($data, [
                'latestStatus' => $latestStatus,
                'latestConfidence' => $latestConfidence,
                'previousStatus' => $previousStatus,
                'previousConfidence' => $previousConfidence,
                'trend' => $trend,
            ]);
        }

        return $concepts;
    }

    private function determineConceptTrend(array $history): string
    {
        if (empty($history)) {
            return self::TREND_INSUFFICIENT;
        }

        if (count($history) === 1) {
            $status = strtoupper((string) ($history[0]['status'] ?? ''));
            if ($status === self::STATUS_NEEDS_ATTENTION) {
                return self::TREND_NEEDS_ATTENTION;
            }
            return self::TREND_NEW;
        }

        $latest = strtoupper((string) ($history[count($history) - 1]['status'] ?? ''));
        $previous = strtoupper((string) ($history[count($history) - 2]['status'] ?? ''));

        $ranked = [
            self::STATUS_NEEDS_ATTENTION => 0,
            self::STATUS_INSUFFICIENT => 1,
            self::STATUS_DEVELOPING => 2,
            self::STATUS_STRONG => 3,
        ];

        $latestRank = $ranked[$latest] ?? 1;
        $previousRank = $ranked[$previous] ?? 1;

        if ($latestRank > $previousRank) {
            return self::TREND_IMPROVING;
        }
        if ($latestRank < $previousRank) {
            return self::TREND_DECLINING;
        }

        if ($latest === self::STATUS_NEEDS_ATTENTION) {
            return self::TREND_NEEDS_ATTENTION;
        }

        return self::TREND_STABLE;
    }

    // ============================================================
    // STRENGTHS
    // ============================================================
    private function buildStrengths(array $examRecords): array
    {
        $strengthsMap = [];

        foreach ($examRecords as $record) {
            $subject = $record['subject'] ?: 'Unknown';
            $strengths = $record['strengths'] ?? [];

            foreach ($strengths as $strength) {
                $strength = trim((string) $strength);
                if ($strength === '') {
                    continue;
                }
                $key = strtolower($strength);

                if (!isset($strengthsMap[$key])) {
                    $strengthsMap[$key] = [
                        'strength' => $strength,
                        'subject' => $subject,
                        'occurrences' => 0,
                        'latestEvidence' => $record['date'],
                        'analysisId' => $record['analysisId'],
                    ];
                }

                $strengthsMap[$key]['occurrences']++;
                if ($record['date'] > $strengthsMap[$key]['latestEvidence']) {
                    $strengthsMap[$key]['latestEvidence'] = $record['date'];
                    $strengthsMap[$key]['analysisId'] = $record['analysisId'];
                }
            }

            if ($record['mode'] === 'legacy') {
                $qa = $record['questionAnalysis'] ?? [];
                foreach ($qa as $q) {
                    $eval = $q['evaluation'] ?? [];
                    $whatStudentDidWell = trim((string) ($eval['whatStudentDidWell'] ?? ''));
                    if ($whatStudentDidWell === '') {
                        continue;
                    }
                    $key = strtolower($whatStudentDidWell);

                    if (!isset($strengthsMap[$key])) {
                        $strengthsMap[$key] = [
                            'strength' => $whatStudentDidWell,
                            'subject' => $subject,
                            'occurrences' => 0,
                            'latestEvidence' => $record['date'],
                            'analysisId' => $record['analysisId'],
                        ];
                    }

                    $strengthsMap[$key]['occurrences']++;
                    if ($record['date'] > $strengthsMap[$key]['latestEvidence']) {
                        $strengthsMap[$key]['latestEvidence'] = $record['date'];
                        $strengthsMap[$key]['analysisId'] = $record['analysisId'];
                    }
                }
            }
        }

        usort($strengthsMap, fn($a, $b) => $b['occurrences'] <=> $a['occurrences']);

        return array_values($strengthsMap);
    }

    // ============================================================
    // WEAKNESSES
    // ============================================================
    private function buildWeaknesses(array $examRecords): array
    {
        $weaknessesMap = [];

        foreach ($examRecords as $record) {
            $subject = $record['subject'] ?: 'Unknown';

            $weaknesses = array_merge(
                $record['weaknesses'] ?? [],
                $record['needsAttention'] ?? [],
                $record['conceptGaps'] ?? []
            );

            foreach ($weaknesses as $weakness) {
                $weakness = trim((string) $weakness);
                if ($weakness === '') {
                    continue;
                }
                $key = strtolower($weakness);

                if (!isset($weaknessesMap[$key])) {
                    $weaknessesMap[$key] = [
                        'concept' => $weakness,
                        'subject' => $subject,
                        'frequency' => 0,
                        'latestStatus' => self::STATUS_NEEDS_ATTENTION,
                        'trend' => self::TREND_INSUFFICIENT,
                        'recentMistakes' => [],
                        'dates' => [],
                    ];
                }

                $weaknessesMap[$key]['frequency']++;
                $weaknessesMap[$key]['dates'][] = $record['date'];
            }

            $mistakes = $record['mistakes'] ?? [];
            foreach ($mistakes as $mistake) {
                $description = trim((string) ($mistake['description'] ?? ''));
                if ($description === '') {
                    continue;
                }
                $key = strtolower($description);

                if (!isset($weaknessesMap[$key])) {
                    $weaknessesMap[$key] = [
                        'concept' => $description,
                        'subject' => $subject,
                        'frequency' => 0,
                        'latestStatus' => self::STATUS_NEEDS_ATTENTION,
                        'trend' => self::TREND_INSUFFICIENT,
                        'recentMistakes' => [],
                        'dates' => [],
                    ];
                }

                $weaknessesMap[$key]['frequency']++;
                $weaknessesMap[$key]['recentMistakes'][] = [
                    'analysisId' => $record['analysisId'],
                    'date' => $record['date'],
                    'description' => $description,
                    'type' => $mistake['type'] ?? null,
                ];
                $weaknessesMap[$key]['dates'][] = $record['date'];
            }

            $qa = $record['questionAnalysis'] ?? [];
            foreach ($qa as $q) {
                $eval = $q['evaluation'] ?? [];
                $evalStatus = strtoupper((string) ($eval['evaluationStatus'] ?? ''));
                if (!in_array($evalStatus, [self::EVAL_INCORRECT, self::EVAL_PARTIALLY_CORRECT], true)) {
                    continue;
                }

                $mistakeDesc = trim((string) ($eval['mistake'] ?? ''));
                $conceptName = '';
                $concept = $q['concept'] ?? null;
                if (is_array($concept)) {
                    $conceptName = trim((string) ($concept['name'] ?? ''));
                }

                $label = $conceptName !== '' ? $conceptName : ($mistakeDesc !== '' ? $mistakeDesc : 'Incorrect answer');

                $key = strtolower($label);
                if (!isset($weaknessesMap[$key])) {
                    $weaknessesMap[$key] = [
                        'concept' => $label,
                        'subject' => $subject,
                        'frequency' => 0,
                        'latestStatus' => self::STATUS_NEEDS_ATTENTION,
                        'trend' => self::TREND_INSUFFICIENT,
                        'recentMistakes' => [],
                        'dates' => [],
                    ];
                }

                $weaknessesMap[$key]['frequency']++;
                $weaknessesMap[$key]['recentMistakes'][] = [
                    'analysisId' => $record['analysisId'],
                    'date' => $record['date'],
                    'description' => $mistakeDesc ?: $label,
                    'type' => $evalStatus,
                ];
                $weaknessesMap[$key]['dates'][] = $record['date'];
            }
        }

        foreach ($weaknessesMap as $key => $data) {
            $dates = $data['dates'];
            $trend = self::TREND_INSUFFICIENT;
            if (count($dates) >= 2) {
                $firstHalf = array_slice($dates, 0, (int) ceil(count($dates) / 2));
                $secondHalf = array_slice($dates, (int) ceil(count($dates) / 2));
                if (count($secondHalf) > count($firstHalf)) {
                    $trend = self::TREND_DECLINING;
                } elseif (count($secondHalf) < count($firstHalf)) {
                    $trend = self::TREND_IMPROVING;
                } else {
                    $trend = self::TREND_STABLE;
                }
            }
            $weaknessesMap[$key]['trend'] = $trend;
            unset($weaknessesMap[$key]['dates']);
        }

        usort($weaknessesMap, fn($a, $b) => $b['frequency'] <=> $a['frequency']);

        return array_values($weaknessesMap);
    }

    // ============================================================
    // LEARNING PLAN
    // ============================================================
    private function buildLearningPlan(array $examRecords): array
    {
        $concepts = $this->aggregateConcepts($examRecords);
        $priorities = [];
        $maintain = [];
        $recentRecommendations = [];

        foreach ($examRecords as $record) {
            $recentRecommendations = array_merge(
                $recentRecommendations,
                $record['recommendations'] ?? []
            );
        }
        $recentRecommendations = array_values(array_unique(array_map('trim', $recentRecommendations)));
        $recentRecommendations = array_slice($recentRecommendations, 0, 10);

        foreach ($concepts as $conceptName => $conceptData) {
            if ($conceptData['trend'] === self::TREND_DECLINING || $conceptData['latestStatus'] === self::STATUS_NEEDS_ATTENTION) {
                $priorities[] = [
                    'concept' => $conceptName,
                    'subject' => $conceptData['subject'],
                    'priority' => $conceptData['trend'] === self::TREND_DECLINING ? 'HIGH' : 'HIGH',
                    'reason' => $this->buildPriorityReason($conceptData),
                    'recommendedAction' => $this->buildRecommendedAction($conceptData),
                    'source' => 'ANALYSIS_DATA',
                ];
            } elseif ($conceptData['latestStatus'] === self::STATUS_STRONG || $conceptData['trend'] === self::TREND_IMPROVING) {
                $maintain[] = [
                    'concept' => $conceptName,
                    'subject' => $conceptData['subject'],
                    'status' => $conceptData['latestStatus'],
                    'trend' => $conceptData['trend'],
                ];
            }
        }

        usort($priorities, fn($a, $b) => $b['priority'] <=> $a['priority']);

        return [
            'priorities' => array_slice($priorities, 0, 20),
            'maintain' => array_slice($maintain, 0, 20),
            'recentRecommendations' => $recentRecommendations,
        ];
    }

    private function buildPriorityReason(array $conceptData): string
    {
        if ($conceptData['trend'] === self::TREND_DECLINING) {
            return 'Concept is declining across recent exams.';
        }
        if ($conceptData['latestStatus'] === self::STATUS_NEEDS_ATTENTION) {
            return 'Concept needs attention based on recent performance.';
        }
        return 'Concept requires focused practice.';
    }

    private function buildRecommendedAction(array $conceptData): string
    {
        if ($conceptData['trend'] === self::TREND_DECLINING) {
            return 'Review foundational material and practice targeted exercises.';
        }
        if ($conceptData['latestStatus'] === self::STATUS_NEEDS_ATTENTION) {
            return 'Revisit core concepts and solve additional problems.';
        }
        return 'Continue practicing to reinforce understanding.';
    }

    // ============================================================
    // RECOMMENDATIONS
    // ============================================================
    private function buildRecommendations(array $examRecords): array
    {
        $recommendations = [];
        foreach ($examRecords as $record) {
            $recommendations = array_merge(
                $recommendations,
                $record['recommendations'] ?? []
            );
        }
        return array_values(array_unique(array_map('trim', $recommendations)));
    }

    // ============================================================
    // DOUBT CONTEXT
    // ============================================================
    private function buildDoubtContext(array $examRecords): array
    {
        $subjects = [];
        foreach ($examRecords as $record) {
            $subject = $record['subject'] ?: 'Unknown';
            $subjects[$subject] = $subjects[$subject] ?? [];
            $subjects[$subject][] = $record;
        }

        $subjectAverages = [];
        foreach ($subjects as $subjectName => $records) {
            $percentages = [];
            foreach ($records as $r) {
                $p = $r['performance']['percentage'] ?? null;
                if ($p !== null) {
                    $percentages[] = $p;
                }
            }
            if (!empty($percentages)) {
                $subjectAverages[$subjectName] = round(array_sum($percentages) / count($percentages), 2);
            }
        }

        arsort($subjectAverages);
        $strongestSubjects = array_keys(array_slice($subjectAverages, 0, 3, true));
        $weakestSubjects = array_reverse(array_keys(array_slice($subjectAverages, -3, null, true)));

        $improvingSubjects = [];
        $decliningSubjects = [];
        foreach ($subjects as $subjectName => $records) {
            $trend = $this->determineSubjectTrend($records);
            if ($trend === self::TREND_IMPROVING) {
                $improvingSubjects[] = $subjectName;
            } elseif ($trend === self::TREND_DECLINING) {
                $decliningSubjects[] = $subjectName;
            }
        }

        $concepts = $this->aggregateConcepts($examRecords);
        $weakConcepts = [];
        $recommendedTopics = [];
        foreach ($concepts as $conceptName => $conceptData) {
            if ($conceptData['latestStatus'] === self::STATUS_NEEDS_ATTENTION || $conceptData['trend'] === self::TREND_DECLINING) {
                $weakConcepts[] = $conceptName;
                $recommendedTopics[] = $conceptName;
            }
        }

        $recentExam = !empty($examRecords) ? [
            'analysisId' => $examRecords[count($examRecords) - 1]['analysisId'],
            'subject' => $examRecords[count($examRecords) - 1]['subject'],
            'date' => $examRecords[count($examRecords) - 1]['date'],
            'score' => $examRecords[count($examRecords) - 1]['performance']['score'] ?? null,
            'percentage' => $examRecords[count($examRecords) - 1]['performance']['percentage'] ?? null,
        ] : null;

        $recentMistakes = [];
        $repeatedMistakes = [];
        $mistakeCounts = [];
        for ($i = count($examRecords) - 1; $i >= 0; $i--) {
            $record = $examRecords[$i];
            foreach (($record['mistakes'] ?? []) as $mistake) {
                $desc = trim((string) ($mistake['description'] ?? ''));
                if ($desc === '') {
                    continue;
                }
                $recentMistakes[] = [
                    'analysisId' => $record['analysisId'],
                    'date' => $record['date'],
                    'description' => $desc,
                    'type' => $mistake['type'] ?? null,
                ];
                $mistakeCounts[$desc] = ($mistakeCounts[$desc] ?? 0) + 1;
            }
            $qa = $record['questionAnalysis'] ?? [];
            foreach ($qa as $q) {
                $eval = $q['evaluation'] ?? [];
                $evalStatus = strtoupper((string) ($eval['evaluationStatus'] ?? ''));
                if (!in_array($evalStatus, [self::EVAL_INCORRECT, self::EVAL_PARTIALLY_CORRECT], true)) {
                    continue;
                }
                $mistakeDesc = trim((string) ($eval['mistake'] ?? ''));
                $conceptName = '';
                $concept = $q['concept'] ?? null;
                if (is_array($concept)) {
                    $conceptName = trim((string) ($concept['name'] ?? ''));
                }
                $label = $conceptName !== '' ? $conceptName : ($mistakeDesc !== '' ? $mistakeDesc : 'Incorrect answer');
                $recentMistakes[] = [
                    'analysisId' => $record['analysisId'],
                    'date' => $record['date'],
                    'description' => $mistakeDesc ?: $label,
                    'type' => $evalStatus,
                ];
                $mistakeCounts[$label] = ($mistakeCounts[$label] ?? 0) + 1;
            }
            if (count($recentMistakes) >= 10) {
                break;
            }
        }

        arsort($mistakeCounts);
        $repeatedMistakes = array_slice(array_keys($mistakeCounts), 0, 5, true);

        return [
            'strongestSubjects' => array_values($strongestSubjects),
            'weakestSubjects' => array_values($weakestSubjects),
            'decliningSubjects' => $decliningSubjects,
            'improvingSubjects' => $improvingSubjects,
            'weakConcepts' => array_slice($weakConcepts, 0, 10),
            'repeatedMistakes' => array_values($repeatedMistakes),
            'recentExam' => $recentExam,
            'recentMistakes' => array_slice($recentMistakes, 0, 10),
            'recommendedTopics' => array_slice($recommendedTopics, 0, 10),
        ];
    }

    // ============================================================
    // INSIGHTS
    // ============================================================
    private function buildInsights(array $examRecords): array
    {
        $insights = [];

        if (count($examRecords) >= 2) {
            $subjects = [];
            foreach ($examRecords as $record) {
                $subject = $record['subject'] ?: 'Unknown';
                $subjects[$subject] = $subjects[$subject] ?? [];
                $subjects[$subject][] = $record;
            }

            foreach ($subjects as $subjectName => $records) {
                $percentages = [];
                foreach ($records as $r) {
                    $p = $r['performance']['percentage'] ?? null;
                    if ($p !== null) {
                        $percentages[] = $p;
                    }
                }
                if (count($percentages) >= 2) {
                    $first = $percentages[0];
                    $last = $percentages[count($percentages) - 1];
                    $diff = round($last - $first, 2);

                    if ($diff > 0) {
                        $insights[] = [
                            'type' => 'IMPROVEMENT',
                            'title' => $subjectName . ' is improving',
                            'description' => sprintf(
                                'Your %s score improved from %s%% to %s%% across %d completed exams.',
                                $subjectName,
                                $first,
                                $last,
                                count($percentages)
                            ),
                            'subject' => $subjectName,
                            'value' => $diff,
                            'severity' => 'POSITIVE',
                            'evidence' => array_map(fn($r) => $r['analysisId'], $records),
                        ];
                    } elseif ($diff < 0) {
                        $insights[] = [
                            'type' => 'DECLINE',
                            'title' => $subjectName . ' needs attention',
                            'description' => sprintf(
                                'Your %s score declined from %s%% to %s%% across %d completed exams.',
                                $subjectName,
                                $first,
                                $last,
                                count($percentages)
                            ),
                            'subject' => $subjectName,
                            'value' => abs($diff),
                            'severity' => 'NEGATIVE',
                            'evidence' => array_map(fn($r) => $r['analysisId'], $records),
                        ];
                    }
                }
            }
        }

        $concepts = $this->aggregateConcepts($examRecords);
        foreach ($concepts as $conceptName => $conceptData) {
            if ($conceptData['trend'] === self::TREND_IMPROVING && $conceptData['latestStatus'] === self::STATUS_STRONG) {
                $insights[] = [
                    'type' => 'CONCEPT_MASTERED',
                    'title' => 'Mastered ' . $conceptName,
                    'description' => 'You have consistently demonstrated strong understanding of ' . $conceptName . '.',
                    'subject' => $conceptData['subject'],
                    'value' => $conceptData['latestConfidence'] ?? 1.0,
                    'severity' => 'POSITIVE',
                    'evidence' => array_map(fn($h) => $h['analysisId'], $conceptData['history']),
                ];
            } elseif ($conceptData['trend'] === self::TREND_DECLINING || $conceptData['latestStatus'] === self::STATUS_NEEDS_ATTENTION) {
                $insights[] = [
                    'type' => 'CONCEPT_NEEDS_ATTENTION',
                    'title' => $conceptName . ' needs attention',
                    'description' => 'Your performance in ' . $conceptName . ' has declined or needs attention.',
                    'subject' => $conceptData['subject'],
                    'value' => $conceptData['needsAttentionCount'],
                    'severity' => 'NEGATIVE',
                    'evidence' => array_map(fn($h) => $h['analysisId'], $conceptData['history']),
                ];
            }
        }

        $strengths = $this->buildStrengths($examRecords);
        if (!empty($strengths)) {
            $top = $strengths[0];
            $insights[] = [
                'type' => 'STRENGTH',
                'title' => 'Key strength: ' . $top['strength'],
                'description' => 'Your most consistent strength is ' . $top['strength'] . ' (' . $top['subject'] . ').',
                'subject' => $top['subject'],
                'value' => $top['occurrences'],
                'severity' => 'POSITIVE',
                'evidence' => [$top['analysisId']],
            ];
        }

        $weaknesses = $this->buildWeaknesses($examRecords);
        if (!empty($weaknesses)) {
            $top = $weaknesses[0];
            $insights[] = [
                'type' => 'WEAKNESS',
                'title' => 'Area to improve: ' . $top['concept'],
                'description' => $top['concept'] . ' needs more practice (' . $top['subject'] . ').',
                'subject' => $top['subject'],
                'value' => $top['frequency'],
                'severity' => 'NEGATIVE',
                'evidence' => array_map(fn($m) => $m['analysisId'], array_slice($top['recentMistakes'], 0, 3)),
            ];
        }

        return $insights;
    }

    // ============================================================
    // HELPERS
    // ============================================================
    private function resolveQuestionCount(array $record): int
    {
        $examCount = $record['exam']['questionCount'] ?? 0;
        $qaCount = count($record['questionAnalysis'] ?? []);
        return max((int) $examCount, $qaCount);
    }

    private function calculateImprovementFromPrevious(array $examRecords): ?float
    {
        $count = count($examRecords);
        if ($count < 2) {
            return null;
        }

        $latest = $examRecords[$count - 1]['performance']['percentage'] ?? null;
        $previous = $examRecords[$count - 2]['performance']['percentage'] ?? null;

        if ($latest === null || $previous === null) {
            return null;
        }

        return round($latest - $previous, 2);
    }

    private function calculateNumericTrend(array $values): int|float
    {
        if (count($values) < 2) {
            return 0;
        }
        $first = $values[0];
        $last = $values[count($values) - 1];
        return round($last - $first, 2);
    }

    private function determineSubjectTrend(array $records): string
    {
        $percentages = [];
        foreach ($records as $record) {
            $p = $record['performance']['percentage'] ?? null;
            if ($p !== null) {
                $percentages[] = $p;
            }
        }

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
}
