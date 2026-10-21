<?php

declare(strict_types=1);

namespace App\Service\StudyPlan;

use App\Document\Analysis;
use App\Entity\StudyTask;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use App\Repository\StudyTaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

readonly class StudyPlanService
{
    private const CONCEPT_NEEDS_ATTENTION = 'NEEDS_ATTENTION';
    private const CONCEPT_DEVELOPING = 'DEVELOPING';
    private const CONCEPT_STRONG = 'STRONG';
    private const CONCEPT_INSUFFICIENT = 'INSUFFICIENT_EVIDENCE';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private StudyTaskRepository $studyTaskRepository,
        private MongoAnalysisRepository $mongoAnalysisRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function getPlan(User $student): array
    {
        $studentId = $student->getId();
        assert($studentId instanceof Uuid, 'Student id must be a UUID');

        $analyses = $this->synchroniseAiTasks($studentId);

        $tasks = $this->studyTaskRepository->findByStudentId($studentId);

        $total = 0;
        $completed = 0;
        foreach ($tasks as $task) {
            $total++;
            if ($task->isCompleted()) {
                $completed++;
            }
        }

        return [
            'studentId' => (string) $studentId,
            'hasCompletedAnalyses' => count($analyses) > 0,
            'progress' => [
                'total' => $total,
                'completed' => $completed,
                'rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0.0,
            ],
            'tasks' => array_map($this->taskToArray(...), $tasks),
        ];
    }

    public function createManualTask(User $student, string $title, ?string $description, ?string $subject, ?string $topic, string $priority, ?int $estimatedMinutes, ?\DateTimeImmutable $dueDate): StudyTask
    {
        $task = new StudyTask();
        $task->setStudentId($student->getId());
        $task->setTitle($title);
        $task->setDescription($description);
        $task->setSubject($subject);
        $task->setTopic($topic);
        $task->setPriority($priority);
        $task->setEstimatedMinutes($estimatedMinutes);
        $task->setDueDate($dueDate);
        $task->setIsAiRecommended(false);

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
    }

    public function updateTask(User $student, StudyTask $task, array $changes): StudyTask
    {
        $fields = [
            'title' => 'setTitle',
            'description' => 'setDescription',
            'subject' => 'setSubject',
            'topic' => 'setTopic',
            'priority' => 'setPriority',
            'estimatedMinutes' => 'setEstimatedMinutes',
            'dueDate' => 'setDueDate',
            'isCompleted' => 'setIsCompleted',
            'isAiRecommended' => 'setIsAiRecommended',
        ];

        foreach ($fields as $field => $setter) {
            if (array_key_exists($field, $changes)) {
                $task->$setter($changes[$field]);
            }
        }

        $this->entityManager->flush();

        return $task;
    }

    public function findTaskForStudent(User $student, string $taskId): StudyTask
    {
        $studentId = $student->getId();
        assert($studentId instanceof Uuid, 'Student id must be a UUID');

        $task = $this->studyTaskRepository->findByIdAndStudentId($taskId, $studentId);
        if ($task === null) {
            throw new AuthenticationException('Study task not found.');
        }

        return $task;
    }

    public function deleteTask(User $student, StudyTask $task): void
    {
        $task->softDelete();
        $this->entityManager->flush();
    }

    public function taskToDto(StudyTask $task): array
    {
        return $this->taskToArray($task);
    }

    private function synchroniseAiTasks(Uuid $studentId): array
    {
        $studentIdStr = (string) $studentId;
        $analyses = $this->mongoAnalysisRepository->findCompletedByStudentId($studentIdStr);
        if (empty($analyses)) {
            return $analyses;
        }

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

        if (empty($records)) {
            return $analyses;
        }

        usort($records, static fn (array $a, array $b) => $a['completedAt']->getTimestamp() <=> $b['completedAt']->getTimestamp());

        $aggregatedTopics = $this->aggregateTopics($records);
        $aggregatedRecommendations = $this->aggregateRecommendations($records);

        $pending = [];
        foreach ($aggregatedTopics as $entry) {
            $key = $entry['key'];
            if ($this->studyTaskRepository->existsByKeyAndStudentId($key, $studentId)) {
                continue;
            }
            $task = new StudyTask();
            $task->setStudentId($studentId);
            $task->setAiRecommendationKey($key);
            $task->setTitle($entry['title']);
            $task->setDescription($entry['reason']);
            $task->setSubject($entry['subject']);
            $task->setTopic($entry['topic']);
            $task->setPriority($entry['priority']);
            $task->setIsAiRecommended(true);
            $pending[] = $task;
        }

        foreach ($aggregatedRecommendations as $entry) {
            $key = $entry['key'];
            if ($this->studyTaskRepository->existsByKeyAndStudentId($key, $studentId)) {
                continue;
            }
            $task = new StudyTask();
            $task->setStudentId($studentId);
            $task->setAiRecommendationKey($key);
            $task->setTitle($entry['title']);
            $task->setDescription($entry['reason']);
            $task->setSubject($entry['subject']);
            $task->setTopic($entry['topic']);
            $task->setPriority($entry['priority']);
            $task->setIsAiRecommended(true);
            $pending[] = $task;
        }

        if (!empty($pending)) {
            foreach ($pending as $task) {
                $this->entityManager->persist($task);
            }
            $this->entityManager->flush();
            $this->logger->info('Synchronised {count} AI study tasks for student {studentId}.', [
                'count' => count($pending),
                'studentId' => $studentIdStr,
            ]);
        }

        return $analyses;
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

        $extractedMetadata = $exam->getExtractedMetadata() ?? [];
        $subject = (string) ($exam->getSubject() ?: ($this->firstMetadataValue($extractedMetadata, ['examSubjectName', 'subject']) ?: ''));

        $currentExamAnalysis = $aiAnalysis['currentExamAnalysis'] ?? null;
        $isDirect = is_array($currentExamAnalysis);

        if ($isDirect) {
            $weaknesses = $currentExamAnalysis['weaknesses'] ?? [];
            $needsAttention = $currentExamAnalysis['needsAttention'] ?? [];
            $conceptGaps = $currentExamAnalysis['conceptGaps'] ?? [];
            $recommendations = $currentExamAnalysis['recommendations'] ?? [];
            $conceptAssessments = $currentExamAnalysis['conceptAssessments'] ?? [];
            $mistakes = $currentExamAnalysis['mistakes'] ?? [];
        } else {
            $summary = $aiAnalysis['summary'] ?? [];
            $weaknesses = $summary['weaknesses'] ?? $aiAnalysis['weaknesses'] ?? [];
            $needsAttention = $summary['needsAttention'] ?? [];
            $conceptGaps = $aiAnalysis['conceptGaps'] ?? $aiAnalysis['knowledgeGaps'] ?? [];
            $recommendations = $aiAnalysis['recommendations'] ?? $summary['recommendations'] ?? [];
            $conceptAssessments = [];
            $mistakes = $aiAnalysis['mistakes'] ?? [];
        }

        return [
            'analysisId' => $analysis->getAnalysisId(),
            'subject' => $subject,
            'completedAt' => $completedAt,
            'weaknesses' => $this->asStringList($weaknesses),
            'needsAttention' => $this->asStringList($needsAttention),
            'conceptGaps' => $this->asStringList($conceptGaps),
            'recommendations' => $this->asRecommendationsList($recommendations),
            'conceptAssessments' => $this->asConceptAssessments($conceptAssessments),
            'mistakes' => $this->asMistakesList($mistakes),
            'questionAnalysis' => $aiAnalysis['questionAnalysis'] ?? [],
        ];
    }

    private function firstMetadataValue(array $metadata, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (isset($metadata[$key]['value'])) {
                return $metadata[$key]['value'];
            }
        }
        return null;
    }

    private function asStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            if (is_array($item) && isset($item['name'])) {
                $result[] = (string) $item['name'];
            } elseif (is_string($item)) {
                $result[] = $item;
            }
        }
        return array_values(array_filter($result, static fn (string $v) => trim($v) !== ''));
    }

    private function asRecommendationsList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            if (is_array($item) && isset($item['action'])) {
                $result[] = [
                    'action' => (string) $item['action'],
                    'reason' => (string) ($item['reason'] ?? ''),
                    'priority' => (string) ($item['priority'] ?? 'medium'),
                ];
            } elseif (is_string($item) && trim($item) !== '') {
                $result[] = ['action' => $item, 'reason' => '', 'priority' => 'medium'];
            }
        }
        return $result;
    }

    private function asConceptAssessments(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = (string) ($item['concept'] ?? $item['name'] ?? '');
            if (trim($name) === '') {
                continue;
            }
            $result[] = [
                'name' => $name,
                'status' => strtoupper((string) ($item['status'] ?? '')),
                'confidence' => $this->asFloat($item['confidence'] ?? null),
            ];
        }
        return $result;
    }

    private function asMistakesList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $description = (string) ($item['description'] ?? '');
            if (trim($description) === '') {
                continue;
            }
            $result[] = [
                'description' => $description,
                'confidence' => $this->asFloat($item['confidence'] ?? null),
            ];
        }
        return $result;
    }

    private function asFloat(mixed $value): ?float
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    private function aggregateTopics(array $records): array
    {
        $topics = [];
        $seenPerAnalysis = [];

        foreach ($records as $record) {
            $analysisId = $record['analysisId'];
            $subject = $record['subject'] !== '' ? $record['subject'] : 'General';

            $candidates = [];

            foreach ($record['conceptAssessments'] as $assessment) {
                $name = $assessment['name'];
                $status = $assessment['status'];
                if ($status === self::CONCEPT_STRONG || $status === self::CONCEPT_INSUFFICIENT || $status === '') {
                    continue;
                }
                $key = $this->normalizeKey($name);
                $priority = $status === self::CONCEPT_NEEDS_ATTENTION ? 'high' : 'medium';
                $candidates[$key] = $this->candidateFromConcept($name, $subject, $status, $priority, $assessment['confidence']);
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
                if ($status === self::CONCEPT_STRONG || $status === self::CONCEPT_INSUFFICIENT || $status === '') {
                    continue;
                }
                $key = $this->normalizeKey($name);
                if (!isset($candidates[$key])) {
                    $priority = $status === self::CONCEPT_NEEDS_ATTENTION ? 'high' : 'medium';
                    $candidates[$key] = $this->candidateFromConcept($name, $subject, $status, $priority, null);
                }
            }

            foreach (['weaknesses', 'needsAttention', 'conceptGaps'] as $sourceField) {
                foreach ($record[$sourceField] as $name) {
                    if (!self::isValidTopicCandidate($name)) {
                        continue;
                    }
                    $key = $this->normalizeKey($name);
                    if (!isset($candidates[$key])) {
                        $candidates[$key] = $this->candidateFromConcept($name, $subject, self::CONCEPT_NEEDS_ATTENTION, 'medium', null);
                    }
                }
            }

            foreach ($record['mistakes'] as $mistake) {
                $name = $mistake['description'];
                if (!self::isValidTopicCandidate($name)) {
                    continue;
                }
                $key = $this->normalizeKey($name);
                if (!isset($candidates[$key])) {
                    $candidates[$key] = [
                        'name' => $name,
                        'subject' => $subject,
                        'status' => self::CONCEPT_NEEDS_ATTENTION,
                        'priority' => 'medium',
                        'confidence' => $mistake['confidence'],
                    ];
                }
            }

            foreach ($candidates as $key => $candidate) {
                if (isset($seenPerAnalysis[$analysisId][$key])) {
                    continue;
                }
                $seenPerAnalysis[$analysisId][$key] = true;

                if (!isset($topics[$key])) {
                    $topics[$key] = [
                        'topic' => $candidate['name'],
                        'subject' => $candidate['subject'],
                        'evidenceCount' => 0,
                        'recurring' => false,
                        'latestStatus' => null,
                        'latestDate' => null,
                        'priority' => $candidate['priority'],
                        'confidence' => $candidate['confidence'],
                    ];
                }

                $topics[$key]['evidenceCount']++;
                if ($topics[$key]['latestDate'] === null || $record['completedAt']->format('U') > $topics[$key]['latestDate']) {
                    $topics[$key]['latestDate'] = $record['completedAt']->format('U');
                    $topics[$key]['latestStatus'] = $candidate['status'];
                    $topics[$key]['priority'] = $candidate['priority'];
                    $topics[$key]['confidence'] = $candidate['confidence'];
                }
            }
        }

        $result = [];
        foreach ($topics as $normalizedKey => $topic) {
            $recurring = $topic['evidenceCount'] >= 2;
            $subject = $topic['subject'];
            $status = $topic['latestStatus'] ?? self::CONCEPT_NEEDS_ATTENTION;
            $priority = $recurring && $topic['priority'] !== 'high' ? 'high' : $topic['priority'];

            $titleVerb = $status === self::CONCEPT_NEEDS_ATTENTION ? 'Review' : 'Practise';
            $title = $titleVerb . ': ' . $topic['topic'];

            $reason = $this->buildTopicReason($topic, $subject, $recurring, $status);
            $key = 'ai:topic:' . sha1(strtolower($subject) . '|' . strtolower($topic['topic']));

            $result[] = [
                'key' => $key,
                'title' => $title,
                'reason' => $reason,
                'subject' => $subject,
                'topic' => $topic['topic'],
                'priority' => $priority,
            ];
        }

        return $result;
    }

    private function candidateFromConcept(string $name, string $subject, string $status, string $priority, ?float $confidence): array
    {
        return [
            'name' => $name,
            'subject' => $subject,
            'status' => $status,
            'priority' => $priority,
            'confidence' => $confidence,
        ];
    }

    private function buildTopicReason(array $topic, string $subject, bool $recurring, string $status): string
    {
        $parts = [];

        if ($recurring) {
            $parts[] = sprintf(
                'This concept has recurred across %d completed assessments in %s.',
                $topic['evidenceCount'],
                $subject
            );
        } else {
            $parts[] = sprintf(
                'This concept was flagged as %s in a recent %s assessment.',
                $this->statusLabel($status),
                $subject
            );
            $parts[] = sprintf('It appeared as a weakness in %d completed assessment(s).', $topic['evidenceCount']);
        }

        if ($topic['confidence'] !== null) {
            $parts[] = sprintf('Latest model confidence: %.0f%%.', $topic['confidence'] * 100);
        }

        return implode(' ', $parts);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            self::CONCEPT_NEEDS_ATTENTION => 'needing attention',
            self::CONCEPT_DEVELOPING => 'developing',
            default => 'needing attention',
        };
    }

    private function normalizePriority(string $priority): string
    {
        $priority = strtolower(trim($priority));
        return in_array($priority, ['low', 'medium', 'high'], true) ? $priority : 'medium';
    }

    private function aggregateRecommendations(array $records): array
    {
        $result = [];
        foreach ($records as $record) {
            $subject = $record['subject'] !== '' ? $record['subject'] : 'General';
            foreach ($record['recommendations'] as $rec) {
                $action = trim($rec['action']);
                if ($action === '') {
                    continue;
                }
                $key = 'ai:rec:' . sha1(strtolower($subject) . '|' . strtolower($action));
                if (isset($result[$key])) {
                    continue;
                }
                $reason = $rec['reason'] !== ''
                    ? $rec['reason']
                    : sprintf('Suggested next step in %s.', $subject);
                $result[$key] = [
                    'key' => $key,
                    'title' => $action,
                    'reason' => $reason,
                    'subject' => $subject,
                    'topic' => null,
                    'priority' => $this->normalizePriority($rec['priority']),
                ];
            }
        }

        return array_values($result);
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

    private function taskToArray(StudyTask $task): array
    {
        return [
            'id' => (string) $task->getId(),
            'title' => $task->getTitle(),
            'description' => $task->getDescription(),
            'subject' => $task->getSubject(),
            'topic' => $task->getTopic(),
            'priority' => $task->getPriority(),
            'estimatedMinutes' => $task->getEstimatedMinutes(),
            'dueDate' => $task->getDueDate() !== null ? $task->getDueDate()->format('Y-m-d\TH:i:sP') : null,
            'isCompleted' => $task->isCompleted(),
            'isAiRecommended' => $task->isAiRecommended(),
            'createdAt' => $task->getCreatedAt() !== null ? $this->formatDateTime($task->getCreatedAt()) : null,
            'updatedAt' => $task->getUpdatedAt() !== null ? $this->formatDateTime($task->getUpdatedAt()) : null,
        ];
    }

    private function formatDateTime(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d\TH:i:sP');
    }
}
