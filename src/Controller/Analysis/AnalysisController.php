<?php

declare(strict_types=1);

namespace App\Controller\Analysis;

use App\Common\Api\Controller\AbstractApiController;
use App\Common\Api\Response\ApiResponseFactory;
use App\DTO\Analysis\AnalysisCreateResponseDTO;
use App\DTO\Analysis\AnalysisDetailDTO;
use App\DTO\Analysis\AnalysisListResponseDTO;
use App\DTO\Analysis\AnalysisMetadataRequestDTO;
use App\DTO\Analysis\CreateAnalysisRequestDTO;
use App\DTO\Analysis\DocumentsDTO;
use App\DTO\Analysis\ReviewAnalysisRequestDTO;
use App\DTO\Analysis\ReviewAnalysisResponseDTO;
use App\DTO\Analysis\StartAnalysisRequestDTO;
use App\DTO\Analysis\StartAnalysisResponseDTO;
use App\DTO\Analysis\UploadAnalysisDocumentRequestDTO;
use App\Entity\User;
use App\Exception\AnalysisNotFoundException;
use App\Exception\Analysis\AnalysisPageLimitExceededException;
use App\Exception\AuthenticationException;
use App\Exception\AuthorizationException;
use App\Service\Analysis\AnalysisDocumentService;
use App\Service\Analysis\AnalysisProcessingService;
use App\Service\Analysis\AnalysisService;
use App\Service\Analysis\AnalysisWorkflowService;
use App\Service\Analysis\Report\AiAnswerSheetReportService;
use App\Service\Analysis\Settings\AnalysisSettings;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Bundle\SecurityBundle\Security as SymfonySecurity;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AnalysisController extends AbstractApiController
{
    public function __construct(
        private AnalysisService $analysisService,
        private AnalysisDocumentService $analysisDocumentService,
        private AnalysisWorkflowService $analysisWorkflowService,
        private AnalysisProcessingService $analysisProcessingService,
        private AiAnswerSheetReportService $aiAnswerSheetReportService,
        private SymfonySecurity $security,
        private ValidatorInterface $validator,
        private LoggerInterface $logger,
        private AnalysisSettings $analysisSettings,
        ApiResponseFactory $responseFactory,
    ) {
        parent::__construct($responseFactory);
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/analyses', name: 'student_analysis_create', methods: ['POST'])]
    public function createAnalysis(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (AuthorizationException $e) {
            return $this->accessDenied($e->getMessage());
        }

        $createRequest = CreateAnalysisRequestDTO::fromArray($data);

        $errors = $this->validator->validate($createRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            /** @var AnalysisCreateResponseDTO $result */
            $result = $this->analysisService->createAnalysis($user, $createRequest);
        } catch (AnalysisNotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create analysis', [
                'student_id' => (string) $user->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Also dump to stderr for test visibility
            fwrite(STDERR, "CREATE ANALYSIS ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
            return $this->serverError('Unable to create analysis. Please try again.');
        }

        return $this->created('Analysis created successfully.', [
            'analysisId' => $result->analysisId,
            'source' => $result->source,
            'status' => $result->status,
            'examSetId' => $result->examSetId,
            'createdAt' => $result->createdAt,
        ]);
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/analyses', name: 'student_analysis_list', methods: ['GET'])]
    public function listAnalyses(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (AuthorizationException $e) {
            return $this->accessDenied($e->getMessage());
        }

        $page = max(1, (int) ($request->query->get('page', 1)));
        $limit = min(100, max(1, (int) ($request->query->get('limit', 20))));

        try {
            /** @var AnalysisListResponseDTO $result */
            $result = $this->analysisService->getAnalyses($user, $page, $limit);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list analyses', [
                'student_id' => (string) $user->getId(),
                'error' => $e->getMessage(),
            ]);
            return $this->serverError('Unable to retrieve analyses. Please try again.');
        }

        return $this->success('Analyses retrieved successfully.', [
            'items' => array_map(fn($item) => [
                'analysisId' => $item->analysisId,
                'source' => $item->source,
                'status' => $item->status,
                'title' => $item->title,
                'subject' => $item->subject,
                'examSetId' => $item->examSetId,
                'createdAt' => $item->createdAt,
                'updatedAt' => $item->updatedAt,
            ], $result->items),
            'pagination' => [
                'page' => $result->pagination->page,
                'limit' => $result->pagination->limit,
                'totalItems' => $result->pagination->totalItems,
                'totalPages' => $result->pagination->totalPages,
            ],
        ]);
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/analyses/upload-requirements', name: 'student_analysis_upload_requirements', methods: ['GET'])]
    public function getUploadRequirements(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (AuthorizationException $e) {
            return $this->accessDenied($e->getMessage());
        }

        return $this->success('Analysis upload requirements retrieved successfully.', $this->getEffectiveUploadLimits());
    }

    /**
     * Return the effective upload limits configured in AnalysisSettings.
     *
     * This is the single source of truth for both the upload-requirements endpoint
     * and the per-analysis limits, so the values always stay in sync.
     *
     * @return array{maxTotalPages: int, maxFileSize: int, maxFiles: int}
     */
    private function getEffectiveUploadLimits(): array
    {
        $uploadSettings = $this->analysisSettings->getUpload();

        return [
            'maxTotalPages' => $uploadSettings['max_total_pages'],
            'maxFileSize' => $uploadSettings['max_file_size'],
            'maxFiles' => $uploadSettings['max_files'],
        ];
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/analyses/{analysisId}', name: 'student_analysis_get', methods: ['GET'])]
    public function getAnalysis(string $analysisId): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (AuthorizationException $e) {
            return $this->accessDenied($e->getMessage());
        }

        try {
            /** @var AnalysisDetailDTO $result */
            $result = $this->analysisService->getAnalysis($user, $analysisId);
        } catch (AnalysisNotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get analysis', [
                'student_id' => (string) $user->getId(),
                'analysis_id' => $analysisId,
                'error' => $e->getMessage(),
            ]);
            fwrite(STDERR, "GET ANALYSIS ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
            return $this->serverError('Unable to retrieve analysis. Please try again.');
        }

        $documents = $result->documents;
        $exam = $result->exam;
        $processing = $result->processing;
        $aiAnalysis = $result->aiAnalysis;

        // Calculate effective upload limits and usage
        $limits = $this->calculateLimits($documents);

        return $this->success('Analysis retrieved successfully.', [
            'analysisId' => $result->analysisId,
            'studentId' => $result->studentId,
            'source' => $result->source,
            'status' => $result->status,
            'documents' => [
                'questionPaper' => [
                    'fileId' => $documents->questionPaper->fileId,
                    'status' => $documents->questionPaper->status,
                    'originalFilename' => $documents->questionPaper->originalFilename,
                    'mimeType' => $documents->questionPaper->mimeType,
                    'fileSize' => $documents->questionPaper->fileSize,
                ],
                'answerSheets' => array_map(fn($as) => [
                    'fileId' => $as->fileId,
                    'status' => $as->status,
                    'originalFilename' => $as->originalFilename,
                    'mimeType' => $as->mimeType,
                    'fileSize' => $as->fileSize,
                    'page' => $as->page,
                ], $documents->answerSheets),
                // Backward compatibility: first answer sheet as answerSheet
                'answerSheet' => !empty($documents->answerSheets) ? [
                    'fileId' => $documents->answerSheets[0]->fileId,
                    'status' => $documents->answerSheets[0]->status,
                    'originalFilename' => $documents->answerSheets[0]->originalFilename,
                    'mimeType' => $documents->answerSheets[0]->mimeType,
                    'fileSize' => $documents->answerSheets[0]->fileSize,
                ] : null,
            ],
            'exam' => [
                'title' => $exam->title,
                'subject' => $exam->subject,
                'totalMarks' => $exam->totalMarks,
                'questions' => array_map(fn($q) => [
                    'questionId' => $q->questionId,
                    'questionText' => $q->questionText,
                    'type' => $q->type,
                    'marks' => $q->marks,
                    'options' => $q->options,
                ], $exam->questions),
                'extractedQuestions' => $exam->extractedQuestions,
                'extractedAnswers' => $exam->extractedAnswers,
                'questionCount' => $exam->questionCount,
                'answerCount' => $exam->answerCount,
                'extractedAt' => $exam->extractedAt,
                'extractionVersion' => $exam->extractionVersion,
                'extractedMetadata' => $exam->extractedMetadata,
                'extractionResult' => $exam->extractionResult,
                'extractionConfidence' => $exam->extractionConfidence,
            ],
            'processing' => [
                'stage' => $processing->stage,
                'status' => $processing->status,
                'error' => $processing->error,
                'startedAt' => $processing->startedAt,
                'completedAt' => $processing->completedAt,
                'retryCount' => $processing->retryCount,
            ],
            'aiAnalysis' => $aiAnalysis,
            'limits' => $limits,
            'createdAt' => $result->createdAt,
            'updatedAt' => $result->updatedAt,
        ]);
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/analyses/{analysisId}/ai-report', name: 'student_analysis_ai_report', methods: ['GET'])]
    public function downloadAiReport(string $analysisId): Response
    {
        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return new Response('Authentication required.', Response::HTTP_UNAUTHORIZED);
        } catch (AuthorizationException $e) {
            return new Response('Access denied.', Response::HTTP_FORBIDDEN);
        }

        try {
            $result = $this->aiAnswerSheetReportService->generateReportWithFilename($user, $analysisId);
        } catch (AnalysisNotFoundException $e) {
            return new Response('Analysis not found.', Response::HTTP_NOT_FOUND);
        } catch (\LogicException $e) {
            return new Response($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate AI report', [
                'student_id' => (string) $user->getId(),
                'analysis_id' => $analysisId,
                'error' => $e->getMessage(),
            ]);
            return new Response('Unable to generate report. Please try again.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $response = new Response($result['content']);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $result['filename'] . '"');
        $response->headers->set('Cache-Control', 'private, max-age=0, must-revalidate');

        return $response;
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/analyses/{analysisId}/regenerate', name: 'student_analysis_regenerate', methods: ['POST'])]
    public function regenerateAnalysis(string $analysisId, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (AuthorizationException $e) {
            return $this->accessDenied($e->getMessage());
        }

        try {
            $analysis = $this->analysisService->getAnalysisEntity($user, $analysisId);
        } catch (AnalysisNotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get analysis for regeneration', [
                'student_id' => (string) $user->getId(),
                'analysis_id' => $analysisId,
                'error' => $e->getMessage(),
            ]);
            return $this->serverError('Unable to retrieve analysis. Please try again.');
        }

        // Check if metadata is confirmed
        $mongoAnalysis = $this->analysisService->getMongoAnalysis($user, $analysisId);
        if ($mongoAnalysis->getMetadataConfirmationStatus() !== 'CONFIRMED') {
            return $this->unprocessableEntity('Metadata must be confirmed before regenerating analysis.');
        }

        try {
            $this->analysisWorkflowService->regenerate($analysis);
        } catch (\LogicException $e) {
            return $this->unprocessableEntity($e->getMessage(), code: 'BUSINESS_RULE_VIOLATION');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to regenerate AI analysis', [
                'student_id' => (string) $user->getId(),
                'analysis_id' => $analysisId,
                'error' => $e->getMessage(),
            ]);
            return $this->serverError('Unable to regenerate AI analysis. Please try again.');
        }

        return $this->accepted('AI analysis regeneration started.', [
            'analysisId' => $analysisId,
            'status' => 'PROCESSING',
        ]);
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/analyses/{analysisId}/retry', name: 'student_analysis_retry', methods: ['POST'])]
    public function retryAnalysis(string $analysisId, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (AuthorizationException $e) {
            return $this->accessDenied($e->getMessage());
        }

        try {
            $analysis = $this->analysisService->getAnalysisEntity($user, $analysisId);
        } catch (AnalysisNotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get analysis for retry', [
                'student_id' => (string) $user->getId(),
                'analysis_id' => $analysisId,
                'error' => $e->getMessage(),
            ]);
            return $this->serverError('Unable to retrieve analysis. Please try again.');
        }

        // Check if metadata is confirmed
        $mongoAnalysis = $this->analysisService->getMongoAnalysis($user, $analysisId);
        if ($mongoAnalysis->getMetadataConfirmationStatus() !== 'CONFIRMED') {
            return $this->unprocessableEntity('Metadata must be confirmed before retrying analysis.');
        }

        try {
            $this->analysisWorkflowService->retry($analysis);
        } catch (\LogicException $e) {
            return $this->unprocessableEntity($e->getMessage(), code: 'BUSINESS_RULE_VIOLATION');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to retry AI analysis', [
                'student_id' => (string) $user->getId(),
                'analysis_id' => $analysisId,
                'error' => $e->getMessage(),
            ]);
            return $this->serverError('Unable to retry AI analysis. Please try again.');
        }

        return $this->accepted('AI analysis retry started.', [
            'analysisId' => $analysisId,
            'status' => 'PROCESSING',
        ]);
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/analyses/{analysisId}/retry-processing', name: 'student_analysis_retry_processing', methods: ['POST'])]
    public function retryProcessing(string $analysisId, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (AuthorizationException $e) {
            return $this->accessDenied($e->getMessage());
        }

try {
            $analysis = $this->analysisService->getAnalysisEntity($user, $analysisId);
        } catch (AnalysisNotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get analysis for retry', [
                'student_id' => (string) $user->getId(),
                'analysis_id' => $analysisId,
                'error' => $e->getMessage(),
            ]);
            return $this->serverError('Unable to retrieve analysis. Please try again.');
        }

        // Check if metadata is confirmed
        $mongoAnalysis = $this->analysisService->getMongoAnalysis($user, $analysisId);
        if ($mongoAnalysis->getMetadataConfirmationStatus() !== 'CONFIRMED') {
            return $this->unprocessableEntity('Metadata must be confirmed before retrying analysis.');
        }

        // Check if retry is allowed
        $processing = $mongoAnalysis->getProcessing();
        $status = $mongoAnalysis->getStatus();

        // Allow retry from FAILED or PUBLISHED (after failure)
        $retryableStatuses = ['FAILED', 'PUBLISHED'];
        if (!in_array($status, $retryableStatuses, true)) {
            return $this->unprocessableEntity(
                'Analysis cannot be retried from current status: ' . $status,
                code: 'INVALID_STATUS_FOR_RETRY'
            );
        }

        try {
            $this->analysisProcessingService->retryProcessing($analysisId, (string) $user->getId());
        } catch (\LogicException $e) {
            return $this->unprocessableEntity($e->getMessage(), code: 'BUSINESS_RULE_VIOLATION');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to retry processing', [
                'student_id' => (string) $user->getId(),
                'analysis_id' => $analysisId,
                'error' => $e->getMessage(),
            ]);
            return $this->serverError('Unable to retry processing. Please try again.');
        }

        return $this->accepted('Processing retry started.', [
            'analysisId' => $analysisId,
            'status' => 'PROCESSING',
        ]);
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/analyses/{analysisId}/archive', name: 'student_analysis_archive', methods: ['POST'])]
    public function archiveAnalysis(string $analysisId, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (AuthorizationException $e) {
            return $this->accessDenied($e->getMessage());
        }

        try {
            $analysis = $this->analysisService->getAnalysisEntity($user, $analysisId);
        } catch (AnalysisNotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get analysis for archiving', [
                'student_id' => (string) $user->getId(),
                'analysis_id' => $analysisId,
                'error' => $e->getMessage(),
            ]);
            return $this->serverError('Unable to retrieve analysis. Please try again.');
        }

        try {
            $this->analysisWorkflowService->archive($analysis);
        } catch (\LogicException $e) {
            return $this->unprocessableEntity($e->getMessage(), code: 'BUSINESS_RULE_VIOLATION');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to archive analysis', [
                'student_id' => (string) $user->getId(),
                'analysis_id' => $analysisId,
                'error' => $e->getMessage(),
            ]);
            return $this->serverError('Unable to archive analysis. Please try again.');
        }

        return $this->success('Analysis archived successfully.', [
            'analysisId' => $analysisId,
            'status' => 'ARCHIVED',
        ]);
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/analyses/{analysisId}/metadata', name: 'student_analysis_metadata', methods: ['PUT'])]
    public function updateMetadata(string $analysisId, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (AuthorizationException $e) {
            return $this->accessDenied($e->getMessage());
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $metadataRequest = AnalysisMetadataRequestDTO::fromArray($data);

        $errors = $this->validator->validate($metadataRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors);
        }

        try {
            $analysis = $this->analysisService->getAnalysisEntity($user, $analysisId);
        } catch (AnalysisNotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get analysis for metadata update', [
                'student_id' => (string) $user->getId(),
                'analysis_id' => $analysisId,
                'error' => $e->getMessage(),
            ]);
            return $this->serverError('Unable to retrieve analysis. Please try again.');
        }

        try {
            $this->analysisWorkflowService->saveMetadata($analysis, $metadataRequest, $user);
        } catch (\LogicException $e) {
            return $this->unprocessableEntity($e->getMessage(), code: 'BUSINESS_RULE_VIOLATION');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save analysis metadata', [
                'student_id' => (string) $user->getId(),
                'analysis_id' => $analysisId,
                'error' => $e->getMessage(),
            ]);
            return $this->serverError('Unable to save metadata. Please try again.');
        }

        // Return updated analysis
        try {
            $result = $this->analysisService->getAnalysis($user, $analysisId);
        } catch (AnalysisNotFoundException $e) {
            return $this->notFound($e->getMessage());
        }

        $examSet = $result->examSet;
        $examSetId = is_array($examSet) ? ($examSet['id'] ?? null) : null;
        return $this->success('Metadata saved successfully.', [
            'analysisId' => $result->analysisId,
            'status' => $result->status,
            'examSet' => $examSet,
            'subject' => $result->subject,
            'totalMarks' => $result->totalMarks,
            'scoredMarks' => $result->scoredMarks,
            'studentName' => $result->studentName,
            'rollNumber' => $result->rollNumber,
            'metadata' => [
                'examSetId' => $examSetId,
                'subjectName' => $result->subject,
                'totalMarks' => $result->totalMarks,
                'scoredMarks' => $result->scoredMarks,
                'studentName' => $result->studentName,
                'rollNumber' => $result->rollNumber,
            ],
        ]);
    }

    private function getCurrentUser(): User
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        if ($user->getRole() !== \App\Enum\UserRole::Student) {
            throw AuthorizationException::create('Only students can access answer analysis endpoints.');
        }

        return $user;
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/analyses/upload', name: 'student_analysis_upload', methods: ['POST'])]
    public function uploadDocument(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (AuthorizationException $e) {
            return $this->accessDenied($e->getMessage());
        }

        $uploadedFiles = $request->files->all();
        $files = $uploadedFiles['files'] ?? [];
        $analysisId = $request->request->get('analysisId');
        $documentType = $request->request->get('documentType');

        // Handle backward compatibility: single 'file' parameter
        if (empty($files) && $request->files->has('file')) {
            $files = [$request->files->get('file')];
        }

        // Safe upload diagnostics — file contents are never logged.
        $this->logger->debug('Analysis document upload received', [
            'analysis_id' => $analysisId,
            'document_type' => $documentType,
            'request_file_field_names' => array_keys($uploadedFiles),
            'uploaded_file_count' => count($files),
            'uploaded_files' => array_map(
                fn(mixed $f): array => $f instanceof \Symfony\Component\HttpFoundation\File\UploadedFile
                    ? [
                        'original_name' => $f->getClientOriginalName(),
                        'client_mime_type' => $f->getClientMimeType(),
                        'size' => $f->getSize(),
                        'upload_error' => $f->getError(),
                        'is_valid' => $f->isValid(),
                        'temp_path' => $f->getPathname(),
                    ]
                    : ['type' => get_debug_type($f)],
                $files
            ),
        ]);

        if (empty($files)) {
            return $this->unprocessableEntity('Validation failed.', ['files' => ['At least one file is required.']], code: 'VALIDATION_ERROR');
        }

        if (empty($analysisId)) {
            return $this->unprocessableEntity('Validation failed.', ['analysisId' => ['Analysis ID is required.']], code: 'VALIDATION_ERROR');
        }

        if (empty($documentType)) {
            return $this->unprocessableEntity('Validation failed.', ['documentType' => ['Document type is required.']], code: 'VALIDATION_ERROR');
        }

        // Convert uploaded files to array for DTO
        $filesArray = array_values(array_filter($files, fn($f) => $f instanceof \Symfony\Component\HttpFoundation\File\UploadedFile));

        if (empty($filesArray)) {
            return $this->unprocessableEntity('Validation failed.', ['files' => ['No valid files uploaded.']], code: 'VALIDATION_ERROR');
        }

        $dto = new UploadAnalysisDocumentRequestDTO(
            files: $filesArray,
            documentType: $documentType,
        );

        $errors = $this->validator->validate($dto);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors, code: 'VALIDATION_ERROR');
        }

        try {
            $result = $this->analysisDocumentService->uploadDocument($user, $analysisId, $dto);
        } catch (AnalysisNotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (AnalysisPageLimitExceededException $e) {
            return $this->unprocessableEntity($e->getMessage(), $e->getErrorData(), code: 'VALIDATION_ERROR');
        } catch (\InvalidArgumentException $e) {
            $errorData = [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ];
            // Extract custom error data from exception if available
            if (method_exists($e, 'getErrorData')) {
                $errorData = array_merge($errorData, $e->getErrorData());
            }
            return $this->unprocessableEntity($e->getMessage(), $errorData, code: 'VALIDATION_ERROR');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to upload analysis document', [
                'student_id' => (string) $user->getId(),
                'analysis_id' => $analysisId,
                'document_type' => $documentType,
                'error' => $e->getMessage(),
            ]);
            return $this->serverError('Unable to upload document. Please try again.');
        }

        return $this->created('File(s) uploaded successfully.', $result);
    }

    #[Security("is_granted('ROLE_STUDENT')")]
    #[Route('/student/analyses/{analysisId}/review', name: 'student_analysis_review', methods: ['PUT'])]
    public function reviewAnalysis(string $analysisId, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        try {
            $user = $this->getCurrentUser();
        } catch (AuthenticationException $e) {
            return $this->unauthorized($e->getMessage());
        } catch (AuthorizationException $e) {
            return $this->accessDenied($e->getMessage());
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON payload.');
        }

        $reviewRequest = ReviewAnalysisRequestDTO::fromArray($data);

        $errors = $this->validator->validate($reviewRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->unprocessableEntity('Validation failed.', $formattedErrors, code: 'VALIDATION_ERROR');
        }

        try {
            /** @var ReviewAnalysisResponseDTO $result */
            $result = $this->analysisService->confirmReview($user, $analysisId, $reviewRequest);
        } catch (AnalysisNotFoundException $e) {
            return $this->notFound($e->getMessage());
        } catch (\App\Common\Exception\BusinessException $e) {
            return $this->unprocessableEntity($e->getMessage(), code: 'BUSINESS_RULE_VIOLATION');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to confirm analysis review', [
                'student_id' => (string) $user->getId(),
                'analysis_id' => $analysisId,
                'error' => $e->getMessage(),
            ]);
            return $this->serverError('Unable to confirm review. Please try again.');
        }

        return $this->success('Analysis review confirmed successfully.', [
            'analysisId' => $result->analysisId,
            'status' => $result->status,
            'exam' => [
                'title' => $result->exam->title,
                'subject' => $result->exam->subject,
                'totalMarks' => $result->exam->totalMarks,
            ],
            'questionCount' => $result->questionCount,
        ]);
    }

    /**
     * Calculate effective upload limits and current usage for the analysis.
     */
    private function calculateLimits(\App\DTO\Analysis\DocumentsDTO $documents): array
    {
        $limits = $this->getEffectiveUploadLimits();
        $maxTotalPages = $limits['maxTotalPages'];

        // Calculate pages used from existing documents
        $pagesUsed = 0;

        // Question paper pages
        $questionPaper = $documents->questionPaper;
        if ($questionPaper && $questionPaper->fileId) {
            $pagesUsed += $questionPaper->pageCount ?? 1;
        }

        // Answer sheets pages
        foreach ($documents->answerSheets as $answerSheet) {
            if ($answerSheet && $answerSheet->fileId) {
                $pagesUsed += $answerSheet->pageCount ?? 1;
            }
        }

        $pagesRemaining = max(0, $maxTotalPages - $pagesUsed);

        return array_merge($limits, [
            'pagesUsed' => $pagesUsed,
            'pagesRemaining' => $pagesRemaining,
        ]);
    }
}