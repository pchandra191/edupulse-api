<?php

declare(strict_types=1);

namespace App\Service\Analysis;

use App\Entity\Analysis;
use App\Entity\ExamSet;
use App\Entity\User;
use App\Enum\AnalysisSource;
use App\Enum\AnalysisStatus;
use App\Enum\MetadataConfirmationStatus;
use App\Exception\AnalysisNotFoundException;
use App\Message\Analysis\ProcessAnalysisMessage;
use App\Repository\AnalysisRepository;
use App\Repository\ExamSetRepository;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

readonly class AnalysisWorkflowService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AnalysisRepository $pgAnalysisRepository,
        private ExamSetRepository $examSetRepository,
        private MongoAnalysisRepository $mongoAnalysisRepository,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function createDraft(User $student, AnalysisSource $source = AnalysisSource::ExternalUpload): Analysis
    {
        $analysisId = Uuid::v7()->toRfc4122();

        $this->entityManager->beginTransaction();
        try {
            $analysis = new Analysis();
            $analysis->setStudent($student);
            $analysis->setAnalysisId($analysisId);
            $analysis->setSource($source->value);
            $analysis->setStatus(AnalysisStatus::Draft->value);

            $this->entityManager->persist($analysis);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            $this->logger->error('Failed to create PostgreSQL analysis record', [
                'student_id' => (string) $student->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        try {
            $mongoAnalysis = new \App\Document\Analysis();
            $mongoAnalysis->setAnalysisId($analysisId);
            $mongoAnalysis->setStudentId((string) $student->getId());
            $mongoAnalysis->setSource($source->value);
            $mongoAnalysis->setStatus(AnalysisStatus::Draft->value);
            $mongoAnalysis->setDocuments(new \App\Document\Documents());
            $mongoAnalysis->setExam(new \App\Document\Exam());
            $mongoAnalysis->setProcessing(new \App\Document\Processing());

            $dm = $this->mongoAnalysisRepository->getDocumentManager();
            $dm->persist($mongoAnalysis);
            $dm->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create MongoDB analysis document', [
                'analysis_id' => $analysisId,
                'student_id' => (string) $student->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $analysis;
    }

    public function publish(Analysis $analysis): void
    {
        $this->requireNotArchived($analysis);
        $this->requireStatus($analysis, AnalysisStatus::Draft, 'publish');

        // Prerequisite: answer sheet must exist
        $mongoAnalysis = $this->mongoAnalysisRepository->findByAnalysisIdAndStudentId(
            $analysis->getAnalysisId(),
            (string) $analysis->getStudent()->getId()
        );
        if (!$mongoAnalysis) {
            throw new AnalysisNotFoundException('Analysis not found in MongoDB');
        }

        $answerSheet = $mongoAnalysis->getDocuments()->getAnswerSheet();
        if (!$answerSheet || !$answerSheet->getFileId()) {
            throw new \LogicException('Answer sheet is required to publish');
        }

        // Prerequisite: required metadata must be complete (subject + totalMarks)
        if (!$analysis->getSubject() && !$analysis->getSubjectName()) {
            throw new \LogicException('Subject is required to publish');
        }
        if ($analysis->getTotalMarks() === null) {
            throw new \LogicException('Total marks is required to publish');
        }

        $this->transitionStatus($analysis, AnalysisStatus::Published);
        $this->logger->info('Analysis published', ['analysis_id' => $analysis->getAnalysisId()]);
    }

    public function saveMetadata(Analysis $analysis, \App\DTO\Analysis\AnalysisMetadataRequestDTO $metadata, User $student): void
    {
        $this->requireNotArchived($analysis);
        $this->requireStatus($analysis, [AnalysisStatus::Draft, AnalysisStatus::Published, AnalysisStatus::NeedsReview], 'save_metadata');

        // Validate exam set ownership if provided
        if ($metadata->examSetId !== null) {
            $examSet = $this->examSetRepository->findByIdAndStudent($metadata->examSetId, $student);
            if (!$examSet) {
                throw new \LogicException('Exam set not found or access denied');
            }
            $analysis->setExamSet($examSet);
        }

        // Validate subject
        if ($metadata->subjectId !== null) {
            // TODO: Validate subject belongs to student's institute
            $analysis->setSubject(null); // Will be set via repository if needed
            $analysis->setSubjectName($metadata->subjectName);
        } else {
            $analysis->setSubjectName($metadata->subjectName);
        }

        // Save other metadata
        if ($metadata->totalMarks !== null) {
            $analysis->setTotalMarks($metadata->totalMarks);
        }
        if ($metadata->scoredMarks !== null) {
            $analysis->setScoredMarks($metadata->scoredMarks);
        }
        if ($metadata->studentName !== null) {
            $analysis->setStudentName($metadata->studentName);
        }
        if ($metadata->rollNumber !== null) {
            $analysis->setRollNumber($metadata->rollNumber);
        }

        // Update MongoDB exam title from examName - subject - totalMarks
        $this->syncMongoExamTitle($analysis);

        // Validate scored marks doesn't exceed total marks
        if ($analysis->getScoredMarks() !== null && $analysis->getTotalMarks() !== null) {
            if ($analysis->getScoredMarks() > $analysis->getTotalMarks()) {
                throw new \LogicException('Scored marks cannot exceed total marks');
            }
        }

        // Check for duplicate subject in exam set
        if ($analysis->getExamSet() !== null && $metadata->subjectId !== null) {
            // This check would require querying existing analyses in the exam set
            // For now, rely on DB unique constraint
        }

        $this->entityManager->flush();

        // Auto-publish if ready (answer sheet + required metadata)
        try {
            $this->publish($analysis);
        } catch (\LogicException $e) {
            // Not ready to publish yet - remain in DRAFT or PUBLISHED
            $this->logger->debug('Analysis not ready for auto-publish', [
                'analysis_id' => $analysis->getAnalysisId(),
                'reason' => $e->getMessage(),
            ]);
        }

        // If in NEEDS_REVIEW and metadata is complete, transition to CONFIRMED
        if ($analysis->getStatus() === AnalysisStatus::NeedsReview->value
            && $analysis->getTotalMarks() !== null) {
            $this->transitionStatus($analysis, AnalysisStatus::Confirmed);
            $this->logger->info('Analysis metadata completed, transitioning to CONFIRMED', [
                'analysis_id' => $analysis->getAnalysisId(),
            ]);

            // Confirmation is the single trigger for AI analysis: mark the
            // analysis as processing immediately and dispatch exactly one
            // analyze message. Metadata persistence above is unchanged.
            $this->startAiAnalysisAfterConfirmation($analysis, $student);
        }
    }

    /**
     * Start AI analysis after the first successful metadata confirmation.
     *
     * Sets the existing PROCESSING representation (both in PostgreSQL and in
     * the Mongo processing document) and dispatches a single
     * ProcessAnalysisMessage(action="analyze"). Repeated confirmations, or an
     * analysis already processing/completed, must not dispatch again.
     */
    private function startAiAnalysisAfterConfirmation(Analysis $analysis, User $student): void
    {
        $analysisId = $analysis->getAnalysisId();
        $studentId = (string) $student->getId();

        $processingBefore = null;
        $shouldDispatch = false;
        $reason = '';

        try {
            $mongoAnalysis = $this->mongoAnalysisRepository->findByAnalysisIdAndStudentId($analysisId, $studentId);
            if (!$mongoAnalysis) {
                $this->logger->warning('Cannot start AI analysis: MongoDB document missing', [
                    'analysis_id' => $analysisId,
                ]);
                return;
            }

            $processing = $mongoAnalysis->getProcessing();
            $processingBefore = $processing?->getStatus();
            $pgStatus = $analysis->getStatus();

            // Idempotency: only the FIRST confirmation starts analysis.
            $inProgress = in_array($processingBefore, ['PROCESSING'], true)
                || in_array($pgStatus, [AnalysisStatus::Processing->value, AnalysisStatus::Done->value], true);
            $alreadyAnalyzed = $mongoAnalysis->getAiAnalysis() !== null;

            if ($inProgress || $alreadyAnalyzed) {
                $reason = $alreadyAnalyzed ? 'ai_analysis_already_present' : 'already_processing_or_done';
            } else {
                $now = new \DateTimeImmutable();

                // Persist the confirmation on both stores so the analyze
                // handler sees a confirmed-metadata analysis regardless of
                // which store it reads.
                $analysis->setMetadataConfirmationStatus(MetadataConfirmationStatus::Confirmed->value);
                $analysis->setMetadataConfirmedAt($now);
                $analysis->setMetadataConfirmedBy($studentId);

                $analysis->setStatus(AnalysisStatus::Processing->value);
                $this->entityManager->flush();

                $mongoAnalysis->setMetadataConfirmationStatus(MetadataConfirmationStatus::Confirmed->value);
                $mongoAnalysis->setMetadataConfirmedAt($now);
                $mongoAnalysis->setMetadataConfirmedBy($studentId);

                $processing->setStage('ANALYZING');
                $processing->setStatus('PROCESSING');
                $processing->setStartedAt($now);
                $processing->setCompletedAt(null);
                $mongoAnalysis->setStatus(AnalysisStatus::Processing->value);
                $mongoAnalysis->setUpdatedAt($now);
                $this->mongoAnalysisRepository->save($mongoAnalysis);

                $shouldDispatch = true;
                $reason = 'first_confirmation';
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to mark analysis as PROCESSING after confirmation', [
                'analysis_id' => $analysisId,
                'error' => $e->getMessage(),
            ]);

            $this->logger->info(sprintf(
                '[AI-TRIGGER] analysisId=%s metadataConfirmationStatus=%s processingBefore=%s dispatchAnalyze=NO',
                $analysisId,
                'CONFIRMED',
                $processingBefore ?? 'UNKNOWN'
            ));

            return;
        }

        $this->logger->info(sprintf(
            '[AI-TRIGGER] analysisId=%s metadataConfirmationStatus=%s processingBefore=%s dispatchAnalyze=%s',
            $analysisId,
            'CONFIRMED',
            $processingBefore ?? 'UNKNOWN',
            $shouldDispatch ? 'YES' : 'NO'
        ));

        if (!$shouldDispatch) {
            $this->logger->info('AI analysis not dispatched after confirmation', [
                'analysis_id' => $analysisId,
                'reason' => $reason,
                'processing_status' => $processingBefore,
            ]);

            return;
        }

        $this->messageBus->dispatch(ProcessAnalysisMessage::createAnalyze($analysisId, $studentId));

        $this->logger->info('AI analysis dispatched after metadata confirmation', [
            'analysis_id' => $analysisId,
            'action' => 'analyze',
        ]);
    }

    private function syncMongoExamTitle(Analysis $analysis): void
    {
        try {
            $mongoAnalysis = $this->mongoAnalysisRepository->findByAnalysisIdAndStudentId(
                $analysis->getAnalysisId(),
                (string) $analysis->getStudent()->getId()
            );
            if (!$mongoAnalysis) {
                return;
            }

            $exam = $mongoAnalysis->getExam();
            $examSet = $analysis->getExamSet();
            $examName = $examSet?->getExamName() ?? '';
            $subject = $analysis->getSubjectName() ?? $analysis->getSubject()?->getName() ?? '';
            $totalMarks = $analysis->getTotalMarks();

            $parts = array_filter([
                $examName !== '' ? $examName : null,
                $subject !== '' ? $subject : null,
                $totalMarks !== null ? (string)$totalMarks : null,
            ]);

            $title = !empty($parts) ? implode(' - ', $parts) : null;
            $exam->setTitle($title);

            $dm = $this->mongoAnalysisRepository->getDocumentManager();
            $dm->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync MongoDB exam title', [
                'analysis_id' => $analysis->getAnalysisId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function startAnalysis(Analysis $analysis): void
    {
        $this->requireNotArchived($analysis);
        $this->requireStatus($analysis, [AnalysisStatus::Published, AnalysisStatus::Confirmed], 'start_analysis');

        $this->transitionStatus($analysis, AnalysisStatus::Processing);

        $this->messageBus->dispatch(new ProcessAnalysisMessage(
            $analysis->getAnalysisId(),
            (string) $analysis->getStudent()->getId(),
            'analyze',
            false,
            0
        ));

        $this->logger->info('AI analysis started', ['analysis_id' => $analysis->getAnalysisId()]);
    }

    public function analysisCompleted(Analysis $analysis): void
    {
        $this->requireStatus($analysis, AnalysisStatus::Processing, 'analysis_completed');

        $this->transitionStatus($analysis, AnalysisStatus::Done);
        $this->logger->info('AI analysis completed', ['analysis_id' => $analysis->getAnalysisId()]);
    }

    public function analysisFailed(Analysis $analysis): void
    {
        $this->requireStatus($analysis, AnalysisStatus::Processing, 'analysis_failed');

        $this->transitionStatus($analysis, AnalysisStatus::Failed);
        $this->logger->info('AI analysis failed, marked as failed', ['analysis_id' => $analysis->getAnalysisId()]);
    }

    public function retry(Analysis $analysis): void
    {
        $this->requireNotArchived($analysis);
        // New lifecycle: after AI failure, status returns to PUBLISHED (retry-able)
        // Legacy: FAILED state also supported for backward compatibility
        $this->requireStatus($analysis, [AnalysisStatus::Published, AnalysisStatus::Failed], 'retry');

        $this->transitionStatus($analysis, AnalysisStatus::Processing);

        $mongoAnalysis = $this->mongoAnalysisRepository->findByAnalysisIdAndStudentId(
            $analysis->getAnalysisId(),
            (string) $analysis->getStudent()->getId()
        );

        $attemptId = 0;
        if ($mongoAnalysis) {
            $processing = $mongoAnalysis->getProcessing();
            if ($processing) {
                $attemptId = ($processing->getAttemptId() ?? 0);
                if ($attemptId === 0) {
                    $attemptId = ($processing->getRetryCount() ?? 0) + 1;
                }
            }
        }

        $this->messageBus->dispatch(ProcessAnalysisMessage::createRetry(
            $analysis->getAnalysisId(),
            (string) $analysis->getStudent()->getId(),
            $attemptId
        ));

        $this->logger->info('AI analysis retry started', ['analysis_id' => $analysis->getAnalysisId()]);
    }

    public function regenerate(Analysis $analysis): void
    {
        $this->requireNotArchived($analysis);
        $this->requireStatus($analysis, AnalysisStatus::Done, 'regenerate');

        $this->transitionStatus($analysis, AnalysisStatus::Processing);

        // Clear previous AI analysis
        $this->clearMongoAiAnalysis($analysis);

        $this->messageBus->dispatch(new ProcessAnalysisMessage(
            $analysis->getAnalysisId(),
            (string) $analysis->getStudent()->getId(),
            'analyze',
            false,
            0
        ));

        $this->logger->info('AI analysis regeneration started', ['analysis_id' => $analysis->getAnalysisId()]);
    }

    public function archive(Analysis $analysis): void
    {
        $this->requireStatus($analysis, [AnalysisStatus::Draft, AnalysisStatus::Published, AnalysisStatus::Done], 'archive');

        $this->transitionStatus($analysis, AnalysisStatus::Archived);
        $this->logger->info('Analysis archived', ['analysis_id' => $analysis->getAnalysisId()]);
    }

    public function canPublish(Analysis $analysis): bool
    {
        if ($analysis->getStatus() === AnalysisStatus::Archived->value) {
            return false;
        }
        return $analysis->getStatus() === AnalysisStatus::Draft->value;
    }

    public function canStartAnalysis(Analysis $analysis): bool
    {
        if ($analysis->getStatus() === AnalysisStatus::Archived->value) {
            return false;
        }
        return in_array($analysis->getStatus(), [
            AnalysisStatus::Published->value,
            AnalysisStatus::Confirmed->value,
        ], true);
    }

    public function canRegenerate(Analysis $analysis): bool
    {
        if ($analysis->getStatus() === AnalysisStatus::Archived->value) {
            return false;
        }
        return $analysis->getStatus() === AnalysisStatus::Done->value;
    }

    public function canRetry(Analysis $analysis): bool
    {
        if ($analysis->getStatus() === AnalysisStatus::Archived->value) {
            return false;
        }
        return in_array($analysis->getStatus(), [
            AnalysisStatus::Failed->value,
            AnalysisStatus::Published->value,
        ], true);
    }

    public function canArchive(Analysis $analysis): bool
    {
        if ($analysis->getStatus() === AnalysisStatus::Archived->value) {
            return false;
        }
        return in_array($analysis->getStatus(), [
            AnalysisStatus::Draft->value,
            AnalysisStatus::Published->value,
            AnalysisStatus::Done->value,
        ], true);
    }

    public function getEnabledTransitions(Analysis $analysis): array
    {
        $transitions = [];
        if ($this->canPublish($analysis)) $transitions[] = 'publish';
        if ($this->canStartAnalysis($analysis)) $transitions[] = 'start_analysis';
        if ($analysis->getStatus() === AnalysisStatus::Processing->value) {
            $transitions[] = 'analysis_completed';
            $transitions[] = 'analysis_failed';
        }
        if ($this->canRegenerate($analysis)) $transitions[] = 'regenerate';
        if ($this->canArchive($analysis)) $transitions[] = 'archive';
        return $transitions;
    }

    private function requireNotArchived(Analysis $analysis): void
    {
        if ($analysis->getStatus() === AnalysisStatus::Archived->value) {
            throw new \LogicException('Archived analyses cannot be modified');
        }
    }

    private function requireStatus(Analysis $analysis, AnalysisStatus|array $expectedStatus, string $transition): void
    {
        $expected = is_array($expectedStatus) ? array_map(fn($s) => $s->value, $expectedStatus) : [$expectedStatus->value];
        if (!in_array($analysis->getStatus(), $expected, true)) {
            throw new \LogicException(sprintf('Cannot %s analysis in status %s', $transition, $analysis->getStatus()));
        }
    }

    private function transitionStatus(Analysis $analysis, AnalysisStatus $newStatus): void
    {
        $this->entityManager->beginTransaction();
        try {
            $analysis->setStatus($newStatus->value);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            $this->logger->error('Failed to transition analysis status', [
                'analysis_id' => $analysis->getAnalysisId(),
                'new_status' => $newStatus->value,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->syncMongoStatus($analysis);
    }

    private function syncMongoStatus(Analysis $analysis): void
    {
        try {
            $mongoAnalysis = $this->mongoAnalysisRepository->findByAnalysisIdAndStudentId(
                $analysis->getAnalysisId(),
                (string) $analysis->getStudent()->getId()
            );
            if ($mongoAnalysis) {
                $mongoAnalysis->setStatus($analysis->getStatus());
                $processing = $mongoAnalysis->getProcessing();
                if ($processing) {
                    $processing->setStatus($analysis->getStatus());
                }
                $dm = $this->mongoAnalysisRepository->getDocumentManager();
                $dm->flush();
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync MongoDB analysis status', [
                'analysis_id' => $analysis->getAnalysisId(),
                'status' => $analysis->getStatus(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function clearMongoAiAnalysis(Analysis $analysis): void
    {
        try {
            $mongoAnalysis = $this->mongoAnalysisRepository->findByAnalysisIdAndStudentId(
                $analysis->getAnalysisId(),
                (string) $analysis->getStudent()->getId()
            );
            if ($mongoAnalysis) {
                $mongoAnalysis->setAiAnalysis(null);
                $mongoAnalysis->setStatus(AnalysisStatus::Processing->value);
                $dm = $this->mongoAnalysisRepository->getDocumentManager();
                $dm->flush();
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to clear MongoDB AI analysis', [
                'analysis_id' => $analysis->getAnalysisId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}