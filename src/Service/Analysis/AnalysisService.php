<?php

declare(strict_types=1);

namespace App\Service\Analysis;

use App\Document\Analysis as MongoAnalysis;
use App\Document\Documents;
use App\Document\DocumentFile;
use App\Document\Exam;
use App\Document\Processing;
use App\DTO\Analysis\AnalysisCreateResponseDTO;
use App\DTO\Analysis\AnalysisDetailDTO;
use App\DTO\Analysis\AnalysisListResponseDTO;
use App\DTO\Analysis\AnalysisSummaryDTO;
use App\DTO\Analysis\CreateAnalysisRequestDTO;
use App\DTO\Analysis\DocumentsDTO;
use App\DTO\Analysis\DocumentFileDTO;
use App\DTO\Analysis\ExamDTO;
use App\DTO\Analysis\ExamReviewDTO;
use App\DTO\Analysis\PaginationDTO;
use App\DTO\Analysis\ProcessingDTO;
use App\DTO\Analysis\QuestionReviewDTO;
use App\DTO\Analysis\ReviewAnalysisRequestDTO;
use App\DTO\Analysis\ReviewAnalysisResponseDTO;
use App\Entity\User;
use App\Entity\Analysis as PgAnalysis;
use App\Entity\ExamSet;
use App\Enum\AnalysisSource;
use App\Enum\AnalysisStatus;
use App\Exception\AnalysisNotFoundException;
use App\Repository\AnalysisRepository;
use App\Repository\ExamSetRepository;
use App\Repository\MongoDB\AnalysisRepository as MongoAnalysisRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

readonly class AnalysisService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AnalysisRepository $pgAnalysisRepository,
        private ExamSetRepository $examSetRepository,
        private MongoAnalysisRepository $mongoAnalysisRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function createAnalysis(User $student, CreateAnalysisRequestDTO $request): AnalysisCreateResponseDTO
    {
        $analysisId = Uuid::v7()->toRfc4122();

        // Validate and fetch ExamSet if provided
        $examSet = null;
        if ($request->examSetId !== null) {
            $examSet = $this->examSetRepository->findByIdAndStudent($request->examSetId, $student);
            if (!$examSet) {
                throw new AnalysisNotFoundException('Exam set not found or access denied.');
            }
        }

        $this->entityManager->beginTransaction();
        try {
            $pgAnalysis = new PgAnalysis();
            $pgAnalysis->setStudent($student);
            $pgAnalysis->setAnalysisId($analysisId);
            $pgAnalysis->setSource($request->source);
            $pgAnalysis->setStatus(AnalysisStatus::Draft->value);
            if ($examSet !== null) {
                $pgAnalysis->setExamSet($examSet);
            }

            $this->entityManager->persist($pgAnalysis);
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
            $mongoAnalysis = new MongoAnalysis();
            $mongoAnalysis->setAnalysisId($analysisId);
            $mongoAnalysis->setStudentId((string) $student->getId());
            $mongoAnalysis->setSource($request->source);
            $mongoAnalysis->setStatus(AnalysisStatus::Draft->value);
            $mongoAnalysis->setDocuments(new Documents());
            $mongoAnalysis->setExam(new Exam());
            $mongoAnalysis->setProcessing(new Processing());

            $dm = $this->mongoAnalysisRepository->getDocumentManager();
            $dm->persist($mongoAnalysis);
            $dm->flush();
            
            // Debug: Verify the document was saved
            $saved = $this->mongoAnalysisRepository->findByAnalysisId($analysisId);
            $this->logger->info('MongoDB analysis saved', [
                'analysis_id' => $analysisId,
                'student_id' => (string) $student->getId(),
                'saved' => $saved !== null,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create MongoDB analysis document', [
                'analysis_id' => $analysisId,
                'student_id' => (string) $student->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $createdStatus = AnalysisStatus::Draft->value;
        $now = new \DateTimeImmutable();
        $createdAt = $now->format(\DateTimeInterface::ATOM);

        return new AnalysisCreateResponseDTO(
            analysisId: $analysisId,
            source: $request->source,
            status: $createdStatus,
            createdAt: $createdAt,
            examSetId: $request->examSetId,
        );
    }

    public function getAnalysisEntity(User $student, string $analysisId): \App\Entity\Analysis
    {
        $pgAnalysis = $this->pgAnalysisRepository->findByAnalysisIdAndStudent($analysisId, $student);
        if (!$pgAnalysis instanceof \App\Entity\Analysis) {
            throw new AnalysisNotFoundException('Analysis not found.');
        }
        return $pgAnalysis;
    }

    public function getMongoAnalysis(User $student, string $analysisId): \App\Document\Analysis
    {
        $mongoAnalysis = $this->mongoAnalysisRepository->findByAnalysisIdAndStudentId($analysisId, (string) $student->getId());
        if (!$mongoAnalysis instanceof \App\Document\Analysis) {
            throw new AnalysisNotFoundException('Analysis not found.');
        }
        return $mongoAnalysis;
    }

    public function getAnalyses(User $student, int $page = 1, int $limit = 20): AnalysisListResponseDTO
    {
        $totalItems = $this->mongoAnalysisRepository->countByStudentId((string) $student->getId());
        $totalPages = (int) ceil($totalItems / $limit);

        $mongoAnalyses = $this->mongoAnalysisRepository->findByStudentIdPaginated(
            (string) $student->getId(),
            $page,
            $limit
        );

        // Fetch PostgreSQL analyses to get examSetId
        $pgAnalyses = $this->pgAnalysisRepository->findByStudentPaginated($student, $page, $limit);
        $pgAnalysesById = [];
        foreach ($pgAnalyses as $pgAnalysis) {
            $pgAnalysesById[$pgAnalysis->getAnalysisId()] = $pgAnalysis;
        }

        $items = array_map(
            fn(MongoAnalysis $a) => $this->mapToSummary($a, $pgAnalysesById[$a->getAnalysisId()] ?? null),
            $mongoAnalyses
        );

        return new AnalysisListResponseDTO(
            items: $items,
            pagination: new PaginationDTO(
                page: $page,
                limit: $limit,
                totalItems: $totalItems,
                totalPages: $totalPages,
            ),
        );
    }

    public function getAnalysis(User $student, string $analysisId): AnalysisDetailDTO
    {
        $studentId = (string) $student->getId();
        
        $mongoAnalysis = $this->mongoAnalysisRepository->findByAnalysisIdAndStudentId(
            $analysisId,
            $studentId
        );

        if (!$mongoAnalysis instanceof \App\Document\Analysis) {
            throw new AnalysisNotFoundException('Analysis not found.');
        }

        // Also fetch PostgreSQL entity for new metadata fields
        $pgAnalysis = $this->pgAnalysisRepository->findByAnalysisIdAndStudent($analysisId, $student);
        
        return $this->mapToDetail($mongoAnalysis, $pgAnalysis);
    }

    private function mapToSummary(MongoAnalysis $analysis, ?PgAnalysis $pgAnalysis = null): AnalysisSummaryDTO
    {
        $exam = $analysis->getExam();
        $examSetId = $pgAnalysis?->getExamSet()?->getId()?->toRfc4122();
        
        return new AnalysisSummaryDTO(
            analysisId: $analysis->getAnalysisId(),
            source: $analysis->getSource(),
            status: $analysis->getStatus(),
            title: $exam->getTitle() ?? '',
            subject: $exam->getSubject() ?? '',
            examSetId: $examSetId,
            createdAt: $analysis->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $analysis->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    private function mapToDetail(MongoAnalysis $mongoAnalysis, ?PgAnalysis $pgAnalysis = null): AnalysisDetailDTO
    {
        $documents = $mongoAnalysis->getDocuments();
        $exam = $mongoAnalysis->getExam();
        $processing = $mongoAnalysis->getProcessing();

        $questions = array_map(
            fn(array $q) => new \App\DTO\Analysis\QuestionDTO(
                questionId: $q['questionId'] ?? '',
                questionText: $q['questionText'] ?? '',
                type: $q['type'] ?? '',
                marks: $q['marks'] ?? 0,
                options: $q['options'] ?? [],
            ),
            $exam->getQuestions()
        );

        // Map extracted questions with provenance
        $extractedQuestions = array_map(function (array $q) {
            return new \App\DTO\Analysis\ExtractedQuestionDTO(
                questionNumber: new \App\DTO\Analysis\ProvenanceValueDTO(
                    value: $q['questionNumber']['value'] ?? '',
                    status: $q['questionNumber']['status'] ?? 'MISSING',
                    confidence: $q['questionNumber']['confidence'] ?? 0.0,
                ),
                questionText: new \App\DTO\Analysis\ProvenanceValueDTO(
                    value: $q['questionText']['value'] ?? '',
                    status: $q['questionText']['status'] ?? 'MISSING',
                    confidence: $q['questionText']['confidence'] ?? 0.0,
                ),
                marks: new \App\DTO\Analysis\ProvenanceValueDTO(
                    value: $q['marks']['value'] ?? null,
                    status: $q['marks']['status'] ?? 'MISSING',
                    confidence: $q['marks']['confidence'] ?? 0.0,
                ),
                type: new \App\DTO\Analysis\ProvenanceValueDTO(
                    value: $q['type']['value'] ?? '',
                    status: $q['type']['status'] ?? 'MISSING',
                    confidence: $q['type']['confidence'] ?? 0.0,
                ),
                source: $q['source'] ?? ['page' => null, 'bbox' => null],
                answerText: isset($q['answerText']) ? new \App\DTO\Analysis\ProvenanceValueDTO(
                    value: $q['answerText']['value'] ?? '',
                    status: $q['answerText']['status'] ?? 'MISSING',
                    confidence: $q['answerText']['confidence'] ?? 0.0,
                ) : null,
                answerSource: $q['answerSource'] ?? null,
            );
        }, $exam->getExtractedQuestions());

        // Map extracted answers
        $extractedAnswers = array_map(function (array $a) {
            return new \App\DTO\Analysis\ExtractedAnswerDTO(
                questionNumber: $a['questionNumber'] ?? '',
                answerText: $a['answerText'] ?? '',
                source: $a['source'] ?? ['page' => null, 'bbox' => null],
            );
        }, $exam->getExtractedAnswers());

        // Get exam set and metadata from PostgreSQL if available
        $examSet = null;
        $subject = $pgAnalysis?->getSubject()?->getName() ?? $pgAnalysis?->getSubjectName();
        $totalMarks = $pgAnalysis?->getTotalMarks() ?? $exam->getTotalMarks();
        $scoredMarks = $pgAnalysis?->getScoredMarks();
        $studentName = $pgAnalysis?->getStudentName();
        $rollNumber = $pgAnalysis?->getRollNumber();
        
        if ($pgAnalysis && $pgAnalysis->getExamSet()) {
            $es = $pgAnalysis->getExamSet();
            $examSet = [
                'id' => $es->getId(),
                'academicYear' => $es->getAcademicYear(),
                'academicClass' => $es->getAcademicClass(),
                'section' => $es->getSection(),
                'examName' => $es->getExamName(),
                'examSubType' => $es->getExamSubType(),
            ];
        }

        return new AnalysisDetailDTO(
            analysisId: $mongoAnalysis->getAnalysisId(),
            studentId: $mongoAnalysis->getStudentId(),
            source: $mongoAnalysis->getSource(),
            status: $mongoAnalysis->getStatus(),
            documents: new DocumentsDTO(
                questionPaper: new DocumentFileDTO(
                    fileId: $documents->getQuestionPaper()->getFileId(),
                    status: $documents->getQuestionPaper()->getStatus(),
                ),
                answerSheet: new DocumentFileDTO(
                    fileId: $documents->getAnswerSheet()->getFileId(),
                    status: $documents->getAnswerSheet()->getStatus(),
                ),
            ),
            exam: new ExamDTO(
                title: $exam->getTitle(),
                subject: $subject,
                totalMarks: $totalMarks,
                questions: $questions,
                extractedQuestions: $extractedQuestions,
                extractedAnswers: $extractedAnswers,
                questionCount: $exam->getQuestionCount(),
                answerCount: $exam->getAnswerCount(),
                extractedAt: $exam->getExtractedAt()?->format(\DateTimeInterface::ATOM),
                extractionVersion: $exam->getExtractionVersion(),
                extractedMetadata: $exam->getExtractedMetadata() ?? [],
                extractionResult: $exam->getExtractionResult() ?? '',
                extractionConfidence: $exam->getExtractionConfidence() ?? 0.0,
            ),
            processing: new ProcessingDTO(
                stage: $processing->getStage(),
                status: $processing->getStatus(),
                error: $processing->getError(),
                startedAt: $processing->getStartedAt()?->format(\DateTimeInterface::ATOM),
                completedAt: $processing->getCompletedAt()?->format(\DateTimeInterface::ATOM),
                retryCount: $processing->getRetryCount() ?? 0,
            ),
            aiAnalysis: $mongoAnalysis->getAiAnalysis(),
            examSet: $examSet,
            subject: $subject,
            totalMarks: $totalMarks,
            scoredMarks: $scoredMarks,
            studentName: $studentName,
            rollNumber: $rollNumber,
            metadataReady: $pgAnalysis?->getSubject() !== null || $pgAnalysis?->getSubjectName() !== null,
            answerSheetUploaded: $documents->getAnswerSheet()->getStatus() === 'UPLOADED',
            createdAt: $mongoAnalysis->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $mongoAnalysis->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            documentClassification: $mongoAnalysis->getDocumentClassification(),
        );
    }

    public function confirmReview(User $student, string $analysisId, ReviewAnalysisRequestDTO $request): ReviewAnalysisResponseDTO
    {
        $studentId = (string) $student->getId();

        // Get MongoDB document first to check if already confirmed
        $mongoAnalysis = $this->mongoAnalysisRepository->findByAnalysisIdAndStudentId($analysisId, $studentId);
        if (!$mongoAnalysis instanceof MongoAnalysis) {
            throw new AnalysisNotFoundException('Analysis not found.');
        }

        // Check if already confirmed - idempotent
        if ($mongoAnalysis->getStatus() === AnalysisStatus::Confirmed->value) {
            $this->logger->info('Analysis already confirmed, returning current state', [
                'analysis_id' => $analysisId,
            ]);
            return new ReviewAnalysisResponseDTO(
                analysisId: $analysisId,
                status: AnalysisStatus::Confirmed->value,
                exam: new ExamReviewDTO(
                    title: $mongoAnalysis->getExam()->getTitle() ?? '',
                    subject: $mongoAnalysis->getExam()->getSubject() ?? '',
                    totalMarks: $mongoAnalysis->getExam()->getTotalMarks(),
                ),
                questionCount: $mongoAnalysis->getExam()->getQuestionCount() ?? 0,
            );
        }

        // Verify ownership via PostgreSQL
        $pgAnalysis = $this->pgAnalysisRepository->findByAnalysisIdAndStudent($analysisId, $student);
        if (!$pgAnalysis instanceof PgAnalysis) {
            $this->logger->warning('Analysis not found or access denied for review', [
                'analysis_id' => $analysisId,
                'student_id' => $studentId,
            ]);
            throw new AnalysisNotFoundException('Analysis not found.');
        }

        // Check current status - only allow from NEEDS_REVIEW or EXTRACTED
        $allowedStatuses = [AnalysisStatus::NeedsReview->value, AnalysisStatus::Extracted->value];
        $currentStatus = $pgAnalysis->getStatus();
        if (!in_array($currentStatus, $allowedStatuses, true)) {
            throw new \App\Common\Exception\BusinessException(
                sprintf('Cannot confirm review from status "%s". Analysis must be in NEEDS_REVIEW or EXTRACTED state.', $currentStatus)
            );
        }

        // Validate questions
        $this->validateQuestions($request->questions);

        // Validate total marks match
        $this->validateTotalMarks($request->exam, $request->questions);

        $this->entityManager->beginTransaction();
        try {
            // Update PostgreSQL status
            $pgAnalysis->setStatus(AnalysisStatus::Confirmed->value);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            $this->logger->error('Failed to update PostgreSQL analysis status', [
                'analysis_id' => $analysisId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        try {
            // Update MongoDB document
            $this->updateMongoAnalysis($mongoAnalysis, $request);
            
            $this->logger->info('Analysis review confirmed successfully', [
                'analysis_id' => $analysisId,
                'student_id' => $studentId,
                'question_count' => count($request->questions),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update MongoDB analysis document', [
                'analysis_id' => $analysisId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return new ReviewAnalysisResponseDTO(
            analysisId: $analysisId,
            status: AnalysisStatus::Confirmed->value,
            exam: new ExamReviewDTO(
                title: $request->exam->title,
                subject: $request->exam->subject,
                totalMarks: $request->exam->totalMarks,
            ),
            questionCount: count($request->questions),
        );
    }

    private function validateQuestions(array $questions): void
    {
        $questionNumbers = [];
        
        foreach ($questions as $index => $question) {
            $questionNumber = trim($question->questionNumber);
            
            if (empty($questionNumber)) {
                throw new \App\Common\Exception\BusinessException(
                    sprintf('Question %d: question number is required.', $index + 1)
                );
            }
            
            if (isset($questionNumbers[$questionNumber])) {
                throw new \App\Common\Exception\BusinessException(
                    sprintf('Duplicate question number: %s', $questionNumber)
                );
            }
            $questionNumbers[$questionNumber] = true;
            
            if (empty(trim($question->questionText))) {
                throw new \App\Common\Exception\BusinessException(
                    sprintf('Question %s: question text is required.', $questionNumber)
                );
            }
            
            if ($question->marks === null || $question->marks < 0) {
                throw new \App\Common\Exception\BusinessException(
                    sprintf('Question %s: marks must be a positive number or zero.', $questionNumber)
                );
            }
        }
    }

    private function validateTotalMarks(ExamReviewDTO $exam, array $questions): void
    {
        $sumMarks = array_sum(array_map(fn(QuestionReviewDTO $q) => $q->marks ?? 0, $questions));
        
        if ($exam->totalMarks !== null && $exam->totalMarks !== $sumMarks) {
            throw new \App\Common\Exception\BusinessException(
                sprintf('Question marks total (%d) does not match exam total marks (%d).', $sumMarks, $exam->totalMarks)
            );
        }
    }

    private function updateMongoAnalysis(MongoAnalysis $mongoAnalysis, ReviewAnalysisRequestDTO $request): void
    {
        $exam = $mongoAnalysis->getExam();
        
        // Build confirmed questions array (replacing extracted questions)
        $confirmedQuestions = [];
        foreach ($request->questions as $index => $question) {
            $confirmedQuestions[] = [
                'questionId' => 'q_' . ($index + 1),
                'questionNumber' => trim($question->questionNumber),
                'questionText' => trim($question->questionText),
                'marks' => $question->marks,
                'type' => $question->type ?? 'short_answer',
                'options' => [],
            ];
        }
        
        // Preserve extracted answers and map to new question numbers
        $extractedAnswers = $exam->getExtractedAnswers();
        $oldToNewQuestionNumberMap = [];
        foreach ($request->questions as $question) {
            // If we can map based on position or if the old question number matches
            $oldToNewQuestionNumberMap[trim($question->questionNumber)] = trim($question->questionNumber);
        }
        
        // Update exam with confirmed questions
        $exam->setQuestions($confirmedQuestions);
        $exam->setTitle($request->exam->title);
        $exam->setSubject($request->exam->subject);
        $exam->setTotalMarks($request->exam->totalMarks);
        $exam->setQuestionCount(count($confirmedQuestions));
        
        // Keep extracted questions but mark them as superseded or preserve them
        // The confirmed questions become the authoritative source
        $exam->setExtractedQuestions([]);
        
        // Keep extracted answers but map to new question numbers where possible
        $mappedAnswers = [];
        foreach ($extractedAnswers as $answer) {
            $oldNumber = $answer['questionNumber'] ?? '';
            $newNumber = $oldToNewQuestionNumberMap[$oldNumber] ?? $oldNumber;
            
            $mappedAnswers[] = [
                'questionNumber' => $newNumber,
                'answerText' => $answer['answerText'] ?? '',
                'source' => $answer['source'] ?? ['page' => null, 'bbox' => null],
            ];
        }
        $exam->setExtractedAnswers($mappedAnswers);
        $exam->setAnswerCount(count($mappedAnswers));
        
        // Update status
        $mongoAnalysis->setStatus(AnalysisStatus::Confirmed->value);
        $mongoAnalysis->setUpdatedAt(new \DateTimeImmutable());
        
        // Update processing info
        $processing = $mongoAnalysis->getProcessing();
        $processing->setStage('reviewed');
        $processing->setStatus('CONFIRMED');
        $processing->setCompletedAt(new \DateTimeImmutable());
        
        $this->mongoAnalysisRepository->save($mongoAnalysis);
    }
}