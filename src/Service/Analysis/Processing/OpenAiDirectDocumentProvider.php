<?php

declare(strict_types=1);

namespace App\Service\Analysis\Processing;

use App\DTO\Analysis\Direct\CurrentExamAnalysis;
use App\DTO\Analysis\Direct\DirectExtractionResult;
use App\DTO\Analysis\ExtractedAnswerSheetMetadata;
use App\Exception\PdfRendererUnavailableException;
use App\Exception\VisionApiException;
use App\Service\Analysis\AI\Prompt\ExamAnalysisPromptBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;

class OpenAiDirectDocumentProvider
{
    private const VERSION = '1.0';
    private const EXTRACTION_PROVIDER = 'OPENAI_DIRECT_DOCUMENT';
    private const ANALYSIS_PROVIDER = 'OPENAI_DIRECT_ANALYSIS';

    private HttpClientInterface $httpClient;
    private string $apiKey;
    private string $extractionModel;
    private string $analysisModel;
    private string $pdfDetail;
    private LoggerInterface $logger;
    private string $projectDir;
    private ExamAnalysisPromptBuilder $promptBuilder;

    // NOTE: pdfDetail is currently reserved for future use.
    // The OpenAI Responses API input_file mechanism does not expose a pdf_detail parameter.
    // If a future provider implementation switches to Vision/image-based inputs,
    // pdfDetail can be used to control visual resolution (low/auto/high).

    public function __construct(
        HttpClientInterface $httpClient,
        string $apiKey,
        string $extractionModel,
        string $analysisModel,
        string $pdfDetail,
        LoggerInterface $logger,
        string $projectDir,
        ExamAnalysisPromptBuilder $promptBuilder,
    ) {
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;
        $this->extractionModel = $extractionModel;
        $this->analysisModel = $analysisModel;
        $this->pdfDetail = $pdfDetail;
        $this->logger = $logger;
        $this->projectDir = $projectDir;
        $this->promptBuilder = $promptBuilder;
    }

    /**
      * Stage 1: Extract document data using OpenAI Responses API with input_file
      */
    public function extractDocuments(
        string $answerSheetPath,
        ?string $questionPaperPath,
        array $trustedMetadata = []
    ): DirectExtractionResult {
        $this->logger->info('Starting OpenAI Direct Document extraction', [
            'answer_sheet' => $answerSheetPath,
            'question_paper' => $questionPaperPath,
            'has_question_paper' => $questionPaperPath !== null,
            'trusted_metadata_keys' => array_keys($trustedMetadata),
        ]);

        // Upload files to OpenAI Files API
        $answerSheetFileId = $this->uploadFile($answerSheetPath);
        $questionPaperFileId = $questionPaperPath ? $this->uploadFile($questionPaperPath) : null;

        try {
            // Build the extraction request with input_file
            $inputFiles = [$answerSheetFileId];
            if ($questionPaperFileId) {
                $inputFiles[] = $questionPaperFileId;
            }

            $response = $this->callResponsesApi($inputFiles, self::EXTRACTION_PROMPT, $this->extractionModel);
            $parsed = $this->parseExtractionResponse($response);

            // Validate extraction
            $validation = $this->validateExtraction($parsed);
            if (!$validation['usable']) {
                throw new \RuntimeException('Direct AI extraction validation failed: ' . $validation['reason']);
            }

            // Convert to canonical DTOs
            $extractionResult = $this->toExtractionResult($parsed, $trustedMetadata);

            $this->logger->info('Direct AI extraction completed', [
                'answers_count' => count($extractionResult->extractedAnswers),
                'questions_count' => count($extractionResult->extractedQuestions),
                'confidence' => $extractionResult->confidence,
                'extraction_method' => $extractionResult->extractionMethod,
            ]);

            return $extractionResult;
        } finally {
            // Clean up OpenAI files
            $this->deleteFile($answerSheetFileId);
            if ($questionPaperFileId) {
                $this->deleteFile($questionPaperFileId);
            }
        }
    }

    /**
     * Stage 1+2 combined: Extract and analyze in one OpenAI request.
     * Returns extraction result with required question-level analysis.
     */
    public function extractDocumentsWithPreliminaryAnalysis(
        string $answerSheetPath,
        ?string $questionPaperPath,
        array $trustedMetadata = []
    ): DirectExtractionResult {
        $this->logger->info('Starting OpenAI Direct Document combined extraction+analysis', [
            'answer_sheet' => $answerSheetPath,
            'question_paper' => $questionPaperPath,
            'has_question_paper' => $questionPaperPath !== null,
            'trusted_metadata_keys' => array_keys($trustedMetadata),
        ]);

        $answerSheetFileId = $this->uploadFile($answerSheetPath);
        $questionPaperFileId = $questionPaperPath ? $this->uploadFile($questionPaperPath) : null;

        try {
            $inputFiles = [$answerSheetFileId];
            if ($questionPaperFileId) {
                $inputFiles[] = $questionPaperFileId;
            }

            $prompt = $this->promptBuilder->build($trustedMetadata);

            $response = $this->callResponsesApi($inputFiles, $prompt, $this->extractionModel);
            $parsed = $this->parseExtractionResponse($response);

            $extractionValidation = $this->validateExtraction($parsed);
            if (!$extractionValidation['usable']) {
                throw new \RuntimeException('Direct AI extraction validation failed: ' . $extractionValidation['reason']);
            }

            $extractionResult = $this->toExtractionResult($parsed, $trustedMetadata);

            if (!isset($parsed['questionAnalysis']) || !is_array($parsed['questionAnalysis'])) {
                throw new \RuntimeException('Direct AI combined response missing required questionAnalysis');
            }

            if (!isset($parsed['currentExamAnalysis']) || !is_array($parsed['currentExamAnalysis'])) {
                throw new \RuntimeException('Direct AI combined response missing required currentExamAnalysis');
            }

            $analysisValidation = $this->validateAnalysis($parsed, $extractionResult);
            if (!$analysisValidation['usable']) {
                throw new \RuntimeException('Direct AI combined analysis validation failed: ' . $analysisValidation['reason']);
            }

            $analysis = $this->toCurrentExamAnalysis($parsed);
            $this->logger->info('Direct AI combined analysis completed', [
                'strengths_count' => count($analysis->strengths),
                'weaknesses_count' => count($analysis->weaknesses),
                'concept_assessments_count' => count($analysis->conceptAssessments),
                'question_analysis_count' => count($parsed['questionAnalysis']),
            ]);

            return new DirectExtractionResult(
                metadata: $extractionResult->metadata,
                extractedAnswers: $extractionResult->extractedAnswers,
                extractedQuestions: $extractionResult->extractedQuestions,
                confidence: $extractionResult->confidence,
                extractionMethod: $extractionResult->extractionMethod,
                ocrMetrics: $extractionResult->ocrMetrics,
                documentEvidence: $extractionResult->documentEvidence,
                language: $extractionResult->language,
                analysis: $analysis,
                questionAnalysis: $parsed['questionAnalysis'] ?? null,
            );
        } finally {
            $this->deleteFile($answerSheetFileId);
            if ($questionPaperFileId) {
                $this->deleteFile($questionPaperFileId);
            }
        }
    }

    /**
     * Stage 2: Analyze extracted data using OpenAI Responses API
     * Only called after student confirms metadata and clicks "Start AI Analysis"
     */
    public function analyzeExtractedData(
        DirectExtractionResult $extractionResult,
        ?string $answerSheetPath,
        ?string $questionPaperPath,
        array $trustedMetadata = []
    ): CurrentExamAnalysis {
        $this->logger->info('Starting OpenAI Direct AI analysis', [
            'answers_count' => count($extractionResult->extractedAnswers),
            'questions_count' => count($extractionResult->extractedQuestions),
            'has_question_paper' => $questionPaperPath !== null,
        ]);

        // If we have original PDFs, we can send them again for analysis context
        $inputFiles = [];
        if ($answerSheetPath) {
            $inputFiles[] = $this->uploadFile($answerSheetPath);
        }
        if ($questionPaperPath) {
            $inputFiles[] = $this->uploadFile($questionPaperPath);
        }

        try {
            // Build analysis prompt with extracted data
            $prompt = $this->buildAnalysisPrompt($extractionResult, $trustedMetadata);
            
            $response = $this->callResponsesApi($inputFiles, $prompt, $this->analysisModel);
            $parsed = $this->parseAnalysisResponse($response);

            // Validate analysis
            $validation = $this->validateAnalysis($parsed, $extractionResult);
            if (!$validation['usable']) {
                throw new \RuntimeException('Direct AI analysis validation failed: ' . $validation['reason']);
            }

            $currentExamAnalysis = $this->toCurrentExamAnalysis($parsed);

            $this->logger->info('Direct AI analysis completed', [
                'strengths_count' => count($currentExamAnalysis->strengths),
                'weaknesses_count' => count($currentExamAnalysis->weaknesses),
                'concept_assessments_count' => count($currentExamAnalysis->conceptAssessments),
            ]);

            return $currentExamAnalysis;
        } finally {
            // Clean up OpenAI files
            foreach ($inputFiles as $fileId) {
                $this->deleteFile($fileId);
            }
        }
    }

    /**
     * Upload a file to OpenAI Files API
     */
    private function uploadFile(string $filePath): string
    {
        $fileName = basename($filePath);
        $mimeType = $this->getMimeTypeFromExtension($filePath);

        $this->logger->debug('Uploading file to OpenAI Files API', [
            'file' => $fileName,
            'size' => filesize($filePath),
            'mime_type' => $mimeType,
        ]);

        $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/files', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
            ],
            'body' => [
                'file' => fopen($filePath, 'r'),
                'purpose' => 'user_data',
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        if ($statusCode !== 200 && $statusCode !== 201) {
            $this->logger->error('OpenAI Files API upload failed', [
                'status_code' => $statusCode,
                'response' => $content,
            ]);
            throw new VisionApiException("OpenAI Files API upload failed: {$statusCode}");
        }

        $data = json_decode($content, true);
        $fileId = $data['id'] ?? null;

        if (!$fileId) {
            throw new \RuntimeException('OpenAI Files API did not return file ID');
        }

        $this->logger->debug('File uploaded to OpenAI', ['file_id' => $fileId, 'file' => $fileName]);
        return $fileId;
    }

    /**
     * Delete a file from OpenAI Files API
     */
    private function deleteFile(string $fileId): void
    {
        try {
            $response = $this->httpClient->request('DELETE', "https://api.openai.com/v1/files/{$fileId}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
            ]);

            if ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 204) {
                $this->logger->warning('Failed to delete OpenAI file', [
                    'file_id' => $fileId,
                    'status_code' => $response->getStatusCode(),
                ]);
            } else {
                $this->logger->debug('Deleted OpenAI file', ['file_id' => $fileId]);
            }
        } catch (\Throwable $e) {
            // Non-critical, just log
            $this->logger->warning('Error deleting OpenAI file', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Call OpenAI Responses API with input_file
     */
    protected function callResponsesApi(array $inputFileIds, string $prompt, string $model): array
    {
        $content = [
            ['type' => 'input_text', 'text' => $prompt],
        ];

        foreach ($inputFileIds as $fileId) {
            $content[] = [
                'type' => 'input_file',
                'file_id' => $fileId,
            ];
        }

        $payload = [
            'model' => $model,
            'input' => [
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_object',
                ],
            ],
            'temperature' => 0.1,
            'max_output_tokens' => 8000,
        ];

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/responses', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 180,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            if ($statusCode === 429) {
                $errorType = null;
                $decoded = json_decode($content, true);
                if (is_array($decoded) && isset($decoded['error']['type'])) {
                    $errorType = $decoded['error']['type'];
                }

                $sanitizedContent = preg_replace('/Bearer\s+[A-Za-z0-9_\-.]+/', 'Bearer [REDACTED]', $content);
                $sanitizedContent = preg_replace('/"Authorization"\s*:\s*"[^"]*"/', '"Authorization":"[REDACTED]"', $sanitizedContent);

                $errorMessage = match (true) {
                    $errorType === 'insufficient_quota' => 'OpenAI Direct AI is currently unavailable because the API account has insufficient credits. Please try again later.',
                    $errorType === 'credit_balance_exhausted' => 'OpenAI Direct AI is currently unavailable because the API account has exhausted its credits. Please try again later.',
                    $errorType === 'rate_limit' => 'OpenAI Direct AI rate limit exceeded. Please try again later.',
                    default => 'OpenAI Direct AI API returned HTTP 429.',
                };

                $this->logger->error('OpenAI Responses API returned 429', [
                    'status_code' => $statusCode,
                    'error_type' => $errorType,
                    'response_body' => $sanitizedContent,
                ]);

                throw new VisionApiException($errorMessage, $errorType);
            }

            if ($statusCode !== 200 && $statusCode !== 201) {
                $sanitizedContent = preg_replace('/Bearer\s+[A-Za-z0-9_\-.]+/', 'Bearer [REDACTED]', $content);
                $sanitizedContent = preg_replace('/"Authorization"\s*:\s*"[^"]*"/', '"Authorization":"[REDACTED]"', $sanitizedContent);

                $this->logger->error('OpenAI Responses API returned non-200 status', [
                    'status_code' => $statusCode,
                    'response_body' => $sanitizedContent,
                ]);

                throw new VisionApiException("OpenAI Responses API returned status {$statusCode}: {$sanitizedContent}", null);
            }

            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if (isset($decoded['usage']) && is_array($decoded['usage'])) {
                $this->logger->info('OpenAI Responses API usage', [
                    'model' => $model,
                    'stage' => $this->resolveStageFromPrompt($prompt),
                    'input_tokens' => $decoded['usage']['input_tokens'] ?? null,
                    'output_tokens' => $decoded['usage']['output_tokens'] ?? null,
                    'total_tokens' => $decoded['usage']['total_tokens'] ?? null,
                    'cached_tokens' => $decoded['usage']['cached_tokens'] ?? null,
                ]);
            }

            // Responses API returns { output: [...], ... }
            // We need to extract the text from output
            if (isset($decoded['output']) && is_array($decoded['output'])) {
                foreach ($decoded['output'] as $output) {
                    if (($output['type'] ?? '') === 'message' && isset($output['content'])) {
                        foreach ($output['content'] as $contentItem) {
                            if (($contentItem['type'] ?? '') === 'output_text') {
                                $text = $contentItem['text'] ?? '';
                                return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
                            }
                        }
                    }
                }
            }

            // Fallback: try direct JSON decode
            return $decoded;
        } catch (VisionApiException $e) {
            throw $e;
        } catch (TransportExceptionInterface $e) {
            throw new VisionApiException('OpenAI Responses API connection failed: ' . $e->getMessage(), null, $e);
        } catch (ClientExceptionInterface|ServerExceptionInterface|RedirectionExceptionInterface $e) {
            throw new VisionApiException('OpenAI Responses API error: ' . $e->getMessage(), null, $e);
        }
    }

    private function resolveStageFromPrompt(string $prompt): string
    {
        if (str_contains($prompt, 'currentExamAnalysis')) {
            return 'combined';
        }
        if (str_contains($prompt, 'ANALYZE the extracted answers')) {
            return 'analysis';
        }
        return 'extraction';
    }

    private function buildAnalysisPrompt(DirectExtractionResult $extractionResult, array $trustedMetadata): string
    {
        $trustedJson = json_encode($trustedMetadata, JSON_PRETTY_PRINT);
        $extractionJson = json_encode([
            'extractedAnswers' => $extractionResult->extractedAnswers,
            'extractedQuestions' => $extractionResult->extractedQuestions,
            'extractedMetadata' => $extractionResult->metadata->getMetadataArray(),
            'extractionConfidence' => $extractionResult->confidence,
        ], JSON_PRETTY_PRINT);

        return <<<PROMPT
{self::ANALYSIS_PROMPT}

TRUSTED METADATA (authoritative - never overwrite):
{$trustedJson}

EXTRACTED DATA TO ANALYZE:
{$extractionJson}
PROMPT;
    }

    private function parseExtractionResponse(array $response): array
    {
        // The Responses API with json_object format should return parsed JSON directly
        // But let's handle both cases
        if (isset($response['output'])) {
            // Already handled in callResponsesApi
            return $response;
        }

        // If we get here, it's already parsed
        if (!is_array($response)) {
            throw new \RuntimeException('Invalid extraction response format');
        }

        return $response;
    }

    private function parseAnalysisResponse(array $response): array
    {
        return $this->parseExtractionResponse($response);
    }

    protected function validateExtraction(array $data): array
    {
        $requiredKeys = ['extractedAnswers', 'extractedQuestions', 'extractedMetadata', 'extraction'];
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $data)) {
                return ['usable' => false, 'reason' => "Missing required key: {$key}"];
            }
        }

        $extraction = $data['extraction'] ?? [];
        if (!isset($extraction['status']) || $extraction['status'] !== 'SUCCESS') {
            return ['usable' => false, 'reason' => 'Extraction status not SUCCESS'];
        }

        $confidence = $extraction['confidence'] ?? 0;
        if (!is_numeric($confidence) || $confidence < 0 || $confidence > 1) {
            return ['usable' => false, 'reason' => 'Invalid extraction confidence'];
        }

        if (!is_array($data['extractedAnswers']) || !is_array($data['extractedQuestions'])) {
            return ['usable' => false, 'reason' => 'extractedAnswers and extractedQuestions must be arrays'];
        }

        foreach ($data['extractedAnswers'] as $idx => $answer) {
            if (!is_array($answer) || !isset($answer['answerText']) || !isset($answer['questionNumber'])) {
                return ['usable' => false, 'reason' => "Invalid extractedAnswer at index {$idx}"];
            }
            $conf = $answer['confidence'] ?? 0;
            if (!is_numeric($conf) || $conf < 0 || $conf > 1) {
                return ['usable' => false, 'reason' => "Invalid confidence in extractedAnswer at index {$idx}"];
            }
        }

        foreach ($data['extractedQuestions'] as $idx => $question) {
            if (!is_array($question) || !isset($question['questionNumber']) || !isset($question['questionText'])) {
                return ['usable' => false, 'reason' => "Invalid extractedQuestion at index {$idx}"];
            }
            $conf = $question['confidence'] ?? 0;
            if (!is_numeric($conf) || $conf < 0 || $conf > 1) {
                return ['usable' => false, 'reason' => "Invalid confidence in extractedQuestion at index {$idx}"];
            }
            $marks = $question['marks'] ?? null;
            if ($marks !== null && (!is_numeric($marks) || $marks < 0)) {
                return ['usable' => false, 'reason' => "Invalid marks in extractedQuestion at index {$idx}"];
            }
        }

        return ['usable' => true, 'reason' => 'Valid extraction'];
    }

    protected function validateAnalysis(array $data, DirectExtractionResult $extractionResult): array
    {
        if (!array_key_exists('currentExamAnalysis', $data)) {
            return ['usable' => false, 'reason' => 'Missing currentExamAnalysis'];
        }

        $analysis = $data['currentExamAnalysis'];
        if (!is_array($analysis)) {
            return ['usable' => false, 'reason' => 'currentExamAnalysis must be an array'];
        }

        $requiredArrays = ['strengths', 'weaknesses', 'needsAttention', 'conceptGaps', 'recommendations', 'conceptAssessments', 'mistakes', 'evidence'];
        foreach ($requiredArrays as $key) {
            if (!array_key_exists($key, $analysis) || !is_array($analysis[$key])) {
                return ['usable' => false, 'reason' => "Missing or invalid array: {$key}"];
            }
        }

        $allowedStatuses = ['STRONG', 'DEVELOPING', 'NEEDS_ATTENTION', 'INSUFFICIENT_EVIDENCE'];
        foreach ($analysis['conceptAssessments'] ?? [] as $idx => $concept) {
            if (!is_array($concept) || !isset($concept['status']) || !isset($concept['concept'])) {
                return ['usable' => false, 'reason' => "Invalid conceptAssessment at index {$idx}"];
            }
            if (!in_array($concept['status'], $allowedStatuses, true)) {
                return ['usable' => false, 'reason' => "Invalid concept status '{$concept['status']}' at index {$idx}"];
            }
            $conf = $concept['confidence'] ?? 0;
            if (!is_numeric($conf) || $conf < 0 || $conf > 1) {
                return ['usable' => false, 'reason' => "Invalid confidence in conceptAssessment at index {$idx}"];
            }
        }

        foreach ($analysis['mistakes'] ?? [] as $idx => $mistake) {
            if (!is_array($mistake) || !isset($mistake['questionNumber']) || !isset($mistake['type']) || !isset($mistake['description'])) {
                return ['usable' => false, 'reason' => "Invalid mistake at index {$idx}"];
            }
            $conf = $mistake['confidence'] ?? 0;
            if (!is_numeric($conf) || $conf < 0 || $conf > 1) {
                return ['usable' => false, 'reason' => "Invalid confidence in mistake at index {$idx}"];
            }
        }

        foreach ($analysis['evidence'] ?? [] as $idx => $evidence) {
            if (!is_array($evidence) || !isset($evidence['type']) || !isset($evidence['questionNumber']) || !isset($evidence['reason'])) {
                return ['usable' => false, 'reason' => "Invalid evidence reference at index {$idx}"];
            }
        }

        if (isset($data['questionAnalysis']) && is_array($data['questionAnalysis'])) {
            foreach ($data['questionAnalysis'] as $idx => $qa) {
                if (!is_array($qa) || !isset($qa['questionNumber']) || !isset($qa['status']) || !isset($qa['evaluation']) || !isset($qa['concept']) || !isset($qa['improvement'])) {
                    return ['usable' => false, 'reason' => "Invalid questionAnalysis at index {$idx}"];
                }

                $evaluation = $qa['evaluation'];
                if (!is_array($evaluation) || !isset($evaluation['evaluationStatus']) || !isset($evaluation['correctness'])) {
                    return ['usable' => false, 'reason' => "Invalid evaluation in questionAnalysis at index {$idx}"];
                }

                $concept = $qa['concept'];
                if (!is_array($concept) || !isset($concept['name']) || !isset($concept['status'])) {
                    return ['usable' => false, 'reason' => "Invalid concept in questionAnalysis at index {$idx}"];
                }
                if (!in_array($concept['status'], $allowedStatuses, true)) {
                    return ['usable' => false, 'reason' => "Invalid concept status in questionAnalysis at index {$idx}"];
                }

                $improvement = $qa['improvement'];
                if (!is_array($improvement) || !isset($improvement['whatToLearn']) || !isset($improvement['whatToPractice']) || !isset($improvement['recommendedAction'])) {
                    return ['usable' => false, 'reason' => "Invalid improvement in questionAnalysis at index {$idx}"];
                }
            }
        } else {
            return ['usable' => false, 'reason' => 'Missing required questionAnalysis'];
        }

        $performance = $analysis['performance'] ?? null;
        if ($performance !== null) {
            if (is_array($performance)) {
                $score = $performance['score'] ?? null;
                $totalMarks = $performance['totalMarks'] ?? null;
                $percentage = $performance['percentage'] ?? null;
                if ($score !== null && (!is_numeric($score) || $score < 0)) {
                    return ['usable' => false, 'reason' => 'Invalid performance score'];
                }
                if ($totalMarks !== null && (!is_numeric($totalMarks) || $totalMarks < 0)) {
                    return ['usable' => false, 'reason' => 'Invalid performance totalMarks'];
                }
                if ($percentage !== null && (!is_numeric($percentage) || $percentage < 0 || $percentage > 100)) {
                    return ['usable' => false, 'reason' => 'Invalid performance percentage'];
                }
            }
        }

        return ['usable' => true, 'reason' => 'Valid analysis'];
    }

    private function toExtractionResult(array $data, array $trustedMetadata): DirectExtractionResult
    {
        $metadata = $this->buildMetadata($data['extractedMetadata'] ?? [], $trustedMetadata);
        
        $extractedAnswers = [];
        foreach ($data['extractedAnswers'] ?? [] as $ans) {
            $extractedAnswers[] = new ExtractedAnswer(
                questionNumber: $ans['questionNumber'] ?? '',
                answerText: $ans['answerText'] ?? '',
                page: $ans['page'] ?? null,
                confidence: $ans['confidence'] ?? 0.5,
                sourcePage: $ans['sourcePage'] ?? null,
            );
        }

        $extractedQuestions = [];
        foreach ($data['extractedQuestions'] ?? [] as $q) {
            $extractedQuestions[] = [
                'questionNumber' => $q['questionNumber'] ?? '',
                'subQuestion' => $q['subQuestion'] ?? null,
                'questionText' => $q['questionText'] ?? '',
                'marks' => $q['marks'] ?? null,
                'rawText' => $q['rawText'] ?? '',
                'language' => $q['language'] ?? 'en',
                'confidence' => $q['confidence'] ?? 0.5,
                'questionKnownFromPaper' => $q['questionKnownFromPaper'] ?? false,
            ];
        }

        return new DirectExtractionResult(
            metadata: $metadata,
            extractedAnswers: $extractedAnswers,
            extractedQuestions: $extractedQuestions,
            confidence: $data['extraction']['confidence'] ?? 0.5,
            extractionMethod: self::EXTRACTION_PROVIDER,
            ocrMetrics: $data['extraction']['ocrMetrics'] ?? null,
            documentEvidence: $data['documentEvidence'] ?? null,
            language: $data['extraction']['language'] ?? 'en',
        );
    }

    private function toCurrentExamAnalysis(array $data): CurrentExamAnalysis
    {
        $analysis = $data['currentExamAnalysis'];

        $performance = null;
        if (isset($analysis['performance'])) {
            $performance = new \App\DTO\Analysis\Direct\Performance(
                score: $analysis['performance']['score'] ?? null,
                totalMarks: $analysis['performance']['totalMarks'] ?? null,
                percentage: $analysis['performance']['percentage'] ?? null,
            );
        }

        $conceptAssessments = [];
        foreach ($analysis['conceptAssessments'] ?? [] as $ca) {
            $conceptAssessments[] = new \App\DTO\Analysis\Direct\ConceptAssessment(
                concept: $ca['concept'],
                status: $ca['status'],
                confidence: $ca['confidence'],
                evidence: $ca['evidence'] ?? [],
            );
        }

        $mistakes = [];
        foreach ($analysis['mistakes'] ?? [] as $m) {
            $mistakes[] = new \App\DTO\Analysis\Direct\Mistake(
                questionNumber: $m['questionNumber'],
                subQuestion: $m['subQuestion'] ?? null,
                type: $m['type'],
                description: $m['description'],
                confidence: $m['confidence'],
            );
        }

        $evidence = [];
        foreach ($analysis['evidence'] ?? [] as $e) {
            $evidence[] = new \App\DTO\Analysis\Direct\EvidenceReference(
                type: $e['type'],
                questionNumber: $e['questionNumber'],
                subQuestion: $e['subQuestion'] ?? null,
                reason: $e['reason'] ?? null,
                page: $e['page'] ?? null,
                bbox: $e['bbox'] ?? null,
            );
        }

        return new CurrentExamAnalysis(
            performance: $performance,
            strengths: $analysis['strengths'] ?? [],
            weaknesses: $analysis['weaknesses'] ?? [],
            needsAttention: $analysis['needsAttention'] ?? [],
            conceptGaps: $analysis['conceptGaps'] ?? [],
            recommendations: $analysis['recommendations'] ?? [],
            conceptAssessments: $conceptAssessments,
            mistakes: $mistakes,
            evidence: $evidence,
        );
    }

    private function buildMetadata(array $extracted, array $trusted): ExtractedAnswerSheetMetadata
    {
        // Trusted metadata takes precedence over extracted
        $merge = function (?string $key, ?string $trustedValue, ?string $extractedValue): ?\App\Service\Analysis\Processing\ProvenanceValue {
            if ($trustedValue !== null) {
                return new \App\Service\Analysis\Processing\ProvenanceValue($trustedValue, \App\Service\Analysis\Processing\ExtractionProvenance::Extracted, 1.0);
            }
            if ($extractedValue !== null) {
                return new \App\Service\Analysis\Processing\ProvenanceValue($extractedValue, \App\Service\Analysis\Processing\ExtractionProvenance::Extracted, 0.5);
            }
            return null;
        };

        return new ExtractedAnswerSheetMetadata(
            studentName: $merge('studentName', $trusted['studentName'] ?? null, $extracted['studentName'] ?? null),
            rollNumber: $merge('rollNumber', $trusted['rollNumber'] ?? null, $extracted['rollNumber'] ?? null),
            subject: $merge('subject', $trusted['subject'] ?? null, $extracted['subject'] ?? null),
            examTitle: $merge('examTitle', $trusted['examTitle'] ?? null, $extracted['examTitle'] ?? null),
            academicYear: $merge('academicYear', $trusted['academicYear'] ?? null, $extracted['academicYear'] ?? null),
            class: $merge('class', $trusted['class'] ?? null, $extracted['class'] ?? null),
            section: $merge('section', $trusted['section'] ?? null, $extracted['section'] ?? null),
            totalMarks: $trusted['totalMarks'] !== null 
                ? new \App\Service\Analysis\Processing\ProvenanceValue($trusted['totalMarks'], \App\Service\Analysis\Processing\ExtractionProvenance::Extracted, 1.0)
                : ($extracted['totalMarks'] !== null 
                    ? new \App\Service\Analysis\Processing\ProvenanceValue($extracted['totalMarks'], \App\Service\Analysis\Processing\ExtractionProvenance::Extracted, 0.5)
                    : null),
            scoredMarks: $trusted['scoredMarks'] !== null
                ? new \App\Service\Analysis\Processing\ProvenanceValue($trusted['scoredMarks'], \App\Service\Analysis\Processing\ExtractionProvenance::Extracted, 1.0)
                : ($extracted['scoredMarks'] !== null
                    ? new \App\Service\Analysis\Processing\ProvenanceValue($extracted['scoredMarks'], \App\Service\Analysis\Processing\ExtractionProvenance::Extracted, 0.5)
                    : null),
            examDate: $merge('examDate', $trusted['examDate'] ?? null, $extracted['examDate'] ?? null),
            examSubType: $merge('examSubType', $trusted['examSubType'] ?? null, $extracted['examSubType'] ?? null),
            instituteName: $merge('instituteName', $trusted['instituteName'] ?? null, $extracted['instituteName'] ?? null),
            extractionResult: $extracted['extractionResult'] ?? 'SUCCESS',
            extractionConfidence: $extracted['extractionConfidence'] ?? 0.5,
        );
    }

    private function getMimeTypeFromExtension(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return match ($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
    }
}