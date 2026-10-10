<?php

declare(strict_types=1);

namespace App\Service\Analysis\Processing;

use App\DTO\Analysis\Direct\CurrentExamAnalysis;
use App\DTO\Analysis\Direct\DirectExtractionResult;
use App\DTO\Analysis\ExtractedAnswer;
use App\DTO\Analysis\ExtractedAnswerSheetMetadata;
use App\Exception\PdfRendererUnavailableException;
use App\Exception\VisionApiException;
use App\Service\Analysis\AI\Prompt\ExamAnalysisPromptBuilder;
use App\Service\Analysis\Settings\AnalysisSettings;
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

    // Models that do NOT support the temperature parameter
    private const MODELS_WITHOUT_TEMPERATURE = [
        'gpt-5-nano',
        'gpt-5-mini',
        'gpt-5',
        'gpt-6-luna',
    ];

    private HttpClientInterface $httpClient;
    private string $apiKey;
    private string $extractionModel;
    private string $analysisModel;
    private string $pdfDetail;
    private LoggerInterface $logger;
    private string $projectDir;
    private ExamAnalysisPromptBuilder $promptBuilder;
    private AnalysisSettings $analysisSettings;
    private OpenAiSanitizer $sanitizer;

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
        AnalysisSettings $analysisSettings,
        OpenAiSanitizer $sanitizer,
    ) {
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;
        $this->extractionModel = $extractionModel;
        $this->analysisModel = $analysisModel;
        $this->pdfDetail = $pdfDetail;
        $this->logger = $logger;
        $this->projectDir = $projectDir;
        $this->promptBuilder = $promptBuilder;
        $this->analysisSettings = $analysisSettings;
        $this->sanitizer = $sanitizer;

        $this->logger->info('[DIRECT-AI-MODEL]', [
            'configuredModel' => $this->extractionModel,
            'resolvedModel' => $this->extractionModel,
        ]);
    }

    /**
      * Stage 1: Extract document data using OpenAI Responses API with input_file
      *
      * @param array<string> $answerSheetPaths Ordered answer-sheet file paths (pages/parts of ONE answer sheet)
      */
    public function extractDocuments(
        array $answerSheetPaths,
        ?string $questionPaperPath,
        array $trustedMetadata = []
    ): DirectExtractionResult {
        $this->logger->info('Starting OpenAI Direct Document extraction', [
            'answer_sheet_count' => count($answerSheetPaths),
            'answer_sheets' => array_map(fn(string $p): string => basename($p), $answerSheetPaths),
            'question_paper' => $questionPaperPath,
            'has_question_paper' => $questionPaperPath !== null,
            'trusted_metadata_keys' => array_keys($trustedMetadata),
        ]);

        // Upload all answer-sheet files to OpenAI Files API (in order)
        $answerSheetFileIds = [];
        foreach ($answerSheetPaths as $answerSheetPath) {
            $answerSheetFileIds[] = $this->uploadFile($answerSheetPath);
        }
        $questionPaperFileId = $questionPaperPath ? $this->uploadFile($questionPaperPath) : null;

        try {
            // Build the extraction request with one input_file per answer-sheet file, in order
            $inputFiles = $answerSheetFileIds;
            if ($questionPaperFileId) {
                $inputFiles[] = $questionPaperFileId;
            }

            $response = $this->callResponsesApi($inputFiles, self::EXTRACTION_PROMPT, $this->extractionModel, $trustedMetadata);
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
            foreach ($answerSheetFileIds as $answerSheetFileId) {
                $this->deleteFile($answerSheetFileId);
            }
            if ($questionPaperFileId) {
                $this->deleteFile($questionPaperFileId);
            }
        }
    }

/**
     * Stage 1+2 combined: Extract and analyze in one OpenAI request.
     * Returns extraction result with required question-level analysis.
     *
     * @param array<string>|string $answerSheetPaths Ordered answer-sheet file paths (pages/parts of ONE answer sheet)
     */
    public function extractDocumentsWithPreliminaryAnalysis(
        array|string $answerSheetPaths,
        ?string $questionPaperPath,
        array $trustedMetadata = []
    ): DirectExtractionResult {
        $answerSheetPaths = is_array($answerSheetPaths) ? $answerSheetPaths : [$answerSheetPaths];
        // Calculate total file sizes and page counts for cost check
        $totalFileSize = 0;
        $totalPageCount = 0;
        foreach ($answerSheetPaths as $path) {
            $totalFileSize += filesize($path);
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf') {
                $totalPageCount += $this->getPdfPageCount($path);
            } else {
                $totalPageCount += 1; // Images count as 1 page
            }
        }
        if ($questionPaperPath) {
            $totalFileSize += filesize($questionPaperPath);
            if (strtolower(pathinfo($questionPaperPath, PATHINFO_EXTENSION)) === 'pdf') {
                $totalPageCount += $this->getPdfPageCount($questionPaperPath);
            } else {
                $totalPageCount += 1;
            }
        }

        // Cost safety check
        $this->performCostCheck($answerSheetPaths, $questionPaperPath, $totalFileSize, $totalPageCount);

        $this->logger->info('[DIRECT-AI-START]', [
            'model' => $this->extractionModel,
            'answerSheetCount' => count($answerSheetPaths),
            'questionPaperPresent' => $questionPaperPath !== null,
        ]);

        $this->logger->info('[DIRECT-AI-COST-CHECK]', [
            'analysisId' => $trustedMetadata['analysisId'] ?? 'unknown',
            'model' => $this->extractionModel,
            'answerSheetCount' => count($answerSheetPaths),
            'questionPaperPresent' => $questionPaperPath !== null,
            'pageCount' => $totalPageCount,
            'totalFileSize' => $totalFileSize,
            'maxOutputTokens' => $this->analysisSettings->getDirectAiMaxOutputTokens(),
        ]);

        $this->logger->info('Starting OpenAI Direct Document combined extraction+analysis', [
            'answer_sheet_count' => count($answerSheetPaths),
            'answer_sheets' => array_map(fn(string $p): string => basename($p), $answerSheetPaths),
            'question_paper' => $questionPaperPath,
            'has_question_paper' => $questionPaperPath !== null,
            'trusted_metadata_keys' => array_keys($trustedMetadata),
        ]);

        // Upload all answer-sheet files to OpenAI Files API (in order)
        $answerSheetFileIds = [];
        foreach ($answerSheetPaths as $answerSheetPath) {
            $answerSheetFileIds[] = $this->uploadFile($answerSheetPath);
        }
        $questionPaperFileId = $questionPaperPath ? $this->uploadFile($questionPaperPath) : null;

        try {
            // One input_file per answer-sheet file, in order, followed by the optional question paper
            $inputFiles = $answerSheetFileIds;
            if ($questionPaperFileId) {
                $inputFiles[] = $questionPaperFileId;
            }

            $prompt = $this->promptBuilder->build($trustedMetadata);

            $response = $this->callResponsesApi($inputFiles, $prompt, $this->extractionModel, $trustedMetadata);
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

            $this->logger->info('[DIRECT-AI-SUCCESS]', [
                'questionAnalysisCount' => count($parsed['questionAnalysis'] ?? []),
                'hasCurrentExamAnalysis' => true,
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
            foreach ($answerSheetFileIds as $answerSheetFileId) {
                $this->deleteFile($answerSheetFileId);
            }
            if ($questionPaperFileId) {
                $this->deleteFile($questionPaperFileId);
            }
        }
    }

    /**
      * Stage 2: Analyze extracted data using OpenAI Responses API
      * Only called after student confirms metadata and clicks "Start AI Analysis"
      *
      * @param array<string> $answerSheetPaths Ordered answer-sheet file paths (pages/parts of ONE answer sheet)
      */
    public function analyzeExtractedData(
        DirectExtractionResult $extractionResult,
        array $answerSheetPaths,
        ?string $questionPaperPath,
        array $trustedMetadata = []
    ): CurrentExamAnalysis {
        $this->logger->info('Starting OpenAI Direct AI analysis', [
            'answers_count' => count($extractionResult->extractedAnswers),
            'questions_count' => count($extractionResult->extractedQuestions),
            'answer_sheet_count' => count($answerSheetPaths),
            'has_question_paper' => $questionPaperPath !== null,
        ]);

        // If we have original files, we can send them again for analysis context
        $inputFiles = [];
        foreach ($answerSheetPaths as $answerSheetPath) {
            $inputFiles[] = $this->uploadFile($answerSheetPath);
        }
        if ($questionPaperPath) {
            $inputFiles[] = $this->uploadFile($questionPaperPath);
        }

        try {
            // Build analysis prompt with extracted data
            $prompt = $this->buildAnalysisPrompt($extractionResult, $trustedMetadata);
            
            $response = $this->callResponsesApi($inputFiles, $prompt, $this->analysisModel, $trustedMetadata);
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

        $this->logger->info('[OPENAI-FILES-AUTH]', [
            'apiKeyConfigured' => $this->apiKey !== '',
            'endpoint' => 'https://api.openai.com/v1/files',
        ]);

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
            throw new VisionApiException("OpenAI Files API upload failed: {$statusCode}", null, $statusCode);
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
    protected function callResponsesApi(
        array $inputFileIds,
        string $prompt,
        string $model,
        array $trustedMetadata = []
    ): array {
        $sanitizedPrompt = $this->sanitizer->sanitize($prompt);

        $content = [
            ['type' => 'input_text', 'text' => $sanitizedPrompt],
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
        ];

        // Only include max_output_tokens when explicitly configured.
        $maxOutputTokens = $this->analysisSettings->getDirectAiMaxOutputTokens();
        if ($maxOutputTokens !== null) {
            $payload['max_output_tokens'] = $maxOutputTokens;
        }

        // gpt-5 family models do not accept temperature.
        $hasTemperature = !$this->isModelWithoutTemperature($model);
        if ($hasTemperature) {
            $payload['temperature'] = 0.1;
        }

        $sanitizedPayload = $this->sanitizer->sanitizeArray($payload);

        $encodingStats = $this->sanitizer->getSanitizationStats(
            $prompt,
            $this->sanitizer->sanitize($prompt)
        );

        $this->logger->info('[DIRECT-AI-ENCODING]', [
            'utf8Valid' => $encodingStats['utf8Valid'],
            'controlCharsRemoved' => $encodingStats['controlCharsRemoved'],
        ]);

        $this->logger->info('[DIRECT-AI-REQUEST-PAYLOAD]', [
            'model' => $model,
            'hasTemperature' => $hasTemperature,
            'payloadKeys' => array_keys($sanitizedPayload),
            'maxOutputTokens' => $maxOutputTokens,
        ]);

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/responses', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $sanitizedPayload,
                'timeout' => 180,
            ]);

            $statusCode = $response->getStatusCode();
            $rawResponse = $response->getContent(false);

            if ($statusCode === 429) {
                $errorType = null;
                $decodedError = json_decode($rawResponse, true);

                if (is_array($decodedError) && isset($decodedError['error']['type'])) {
                    $errorType = (string) $decodedError['error']['type'];
                }

                $sanitizedContent = $this->sanitizeApiErrorBody($rawResponse);

                $errorMessage = match ($errorType) {
                    'insufficient_quota' =>
                        'OpenAI Direct AI is currently unavailable because the API account has insufficient credits. Please try again later.',
                    'credit_balance_exhausted' =>
                        'OpenAI Direct AI is currently unavailable because the API account has exhausted its credits. Please try again later.',
                    'rate_limit' =>
                        'OpenAI Direct AI rate limit exceeded. Please try again later.',
                    default =>
                        'OpenAI Direct AI API returned HTTP 429.',
                };

                $this->logger->error('OpenAI Responses API returned 429', [
                    'status_code' => $statusCode,
                    'error_type' => $errorType,
                    'response_body' => $sanitizedContent,
                ]);

                throw new VisionApiException($errorMessage, $errorType, $statusCode);
            }

            if ($statusCode !== 200 && $statusCode !== 201) {
                $sanitizedContent = $this->sanitizeApiErrorBody($rawResponse);
                $errorType = null;
                $decodedError = json_decode($rawResponse, true);

                if (is_array($decodedError) && isset($decodedError['error']['type'])) {
                    $errorType = (string) $decodedError['error']['type'];
                }

                $this->logger->error('OpenAI Responses API returned non-200 status', [
                    'status_code' => $statusCode,
                    'error_type' => $errorType,
                    'response_body' => $sanitizedContent,
                ]);

                throw new VisionApiException(
                    "OpenAI Responses API returned status {$statusCode}: {$sanitizedContent}",
                    $errorType,
                    $statusCode
                );
            }

            $decoded = json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                throw new VisionApiException(
                    'OpenAI Responses API returned an invalid response envelope.',
                    'invalid_response',
                    $statusCode
                );
            }

            /*
             * IMPORTANT:
             * Responses API output is an array of output items. The first item
             * is not guaranteed to be the message containing output_text.
             *
             * Never assume output[0] is the message. Search every output item
             * and every content item for output_text.
             */
            $outputItems = $decoded['output'] ?? [];
            if (!is_array($outputItems)) {
                $outputItems = [];
            }

            $outputTextParts = [];
            $foundMessage = false;
            $foundOutputText = false;

            foreach ($outputItems as $outputItem) {
                if (!is_array($outputItem)) {
                    continue;
                }

                if (($outputItem['type'] ?? null) !== 'message') {
                    continue;
                }

                $foundMessage = true;

                $messageContent = $outputItem['content'] ?? [];
                if (!is_array($messageContent)) {
                    continue;
                }

                foreach ($messageContent as $contentItem) {
                    if (!is_array($contentItem)) {
                        continue;
                    }

                    if (($contentItem['type'] ?? null) !== 'output_text') {
                        continue;
                    }

                    $text = $contentItem['text'] ?? null;
                    if (!is_string($text) || $text === '') {
                        continue;
                    }

                    $foundOutputText = true;
                    $outputTextParts[] = $text;
                }
            }

            $outputText = implode('', $outputTextParts);

            // Log the actual OpenAI response text for debugging
            $this->logger->info('[OPENAI-RESPONSE-TEXT]', [
                'outputText' => $outputText,
            ]);

            // Responses API response status/incomplete_details are top-level.
            $responseStatus = isset($decoded['status']) && is_string($decoded['status'])
                ? $decoded['status']
                : null;

            $incompleteReason = null;
            if (isset($decoded['incomplete_details']) && is_array($decoded['incomplete_details'])) {
                $reason = $decoded['incomplete_details']['reason'] ?? null;
                if (is_string($reason) && $reason !== '') {
                    $incompleteReason = $reason;
                }
            }

            $usage = isset($decoded['usage']) && is_array($decoded['usage'])
                ? $decoded['usage']
                : [];

            $outputTokens = $usage['output_tokens'] ?? null;

            $this->logger->info('[DIRECT-AI-RESPONSE]', [
                'model' => $model,
                'outputItemCount' => count($outputItems),
                'foundMessage' => $foundMessage,
                'foundOutputText' => $foundOutputText,
                'outputTextLength' => strlen($outputText),
                'outputTokens' => $outputTokens,
                'maxOutputTokens' => $maxOutputTokens,
                'responseStatus' => $responseStatus,
                'incompleteReason' => $incompleteReason,
            ]);

            // Log usage while the original Responses API envelope is available.
            $this->logUsageAndCost($decoded, $trustedMetadata);

            if ($incompleteReason !== null || ($responseStatus !== null && $responseStatus !== 'completed')) {
                $reason = $incompleteReason ?? $responseStatus ?? 'unknown';

                $this->logger->error('[DIRECT-AI-RESPONSE-INCOMPLETE]', [
                    'model' => $model,
                    'responseStatus' => $responseStatus,
                    'incompleteReason' => $incompleteReason,
                    'outputTokens' => $outputTokens,
                    'maxOutputTokens' => $maxOutputTokens,
                ]);

                throw new VisionApiException(
                    'Direct AI response was incomplete. Reason: ' . $reason,
                    'incomplete_response',
                    $statusCode
                );
            }

            if (!$foundOutputText || trim($outputText) === '') {
                $this->logger->error('[DIRECT-AI-RESPONSE-NO-OUTPUT-TEXT]', [
                    'model' => $model,
                    'outputItemCount' => count($outputItems),
                    'foundMessage' => $foundMessage,
                    'foundOutputText' => $foundOutputText,
                    'responseStatus' => $responseStatus,
                ]);

                // Do NOT return the raw Responses API envelope.
                throw new VisionApiException(
                    'OpenAI Responses API returned no output_text message content.',
                    'invalid_response',
                    $statusCode
                );
            }

            try {
                $modelData = json_decode($outputText, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->logger->error('[DIRECT-AI-JSON-ERROR]', [
                    'analysisId' => $trustedMetadata['analysisId'] ?? 'unknown',
                    'outputLength' => strlen($outputText),
                    'outputTokens' => $outputTokens,
                    'maxOutputTokens' => $maxOutputTokens,
                    'reason' => 'syntax',
                    'jsonError' => $e->getMessage(),
                ]);

                throw new VisionApiException(
                    'Direct AI response JSON parse failed: ' . $e->getMessage(),
                    'json_parse_error',
                    $statusCode,
                    $e
                );
            }

            if (!is_array($modelData)) {
                throw new VisionApiException(
                    'Direct AI response JSON must decode to an object.',
                    'invalid_response',
                    $statusCode
                );
            }

            return $modelData;
        } catch (VisionApiException $e) {
            throw $e;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('OpenAI Responses API transport failure', [
                'analysisId' => $trustedMetadata['analysisId'] ?? 'unknown',
                'error' => $e->getMessage(),
                'previous' => $e->getPrevious() ? get_class($e->getPrevious()) : null,
            ]);
            throw new VisionApiException(
                'OpenAI Responses API connection failed: ' . $e->getMessage(),
                'transport_failure',
                0,
                $e
            );
        } catch (ClientExceptionInterface $e) {
            $statusCode = $e->getCode() ?: 400;
            $errorType = $statusCode === 429 ? 'rate_limit' : 'invalid_request_error';
            throw new VisionApiException(
                'OpenAI Responses API client error: ' . $e->getMessage(),
                $errorType,
                $statusCode,
                $e
            );
        } catch (ServerExceptionInterface $e) {
            throw new VisionApiException(
                'OpenAI Responses API server error: ' . $e->getMessage(),
                'server_error',
                $e->getCode() ?: 500,
                $e
            );
        } catch (RedirectionExceptionInterface $e) {
            throw new VisionApiException(
                'OpenAI Responses API redirection: ' . $e->getMessage(),
                null,
                $e->getCode() ?: 300,
                $e
            );
        } catch (\JsonException $e) {
            $this->logger->error('[DIRECT-AI-JSON-ERROR]', [
                'analysisId' => $trustedMetadata['analysisId'] ?? 'unknown',
                'reason' => 'response_envelope',
                'jsonError' => $e->getMessage(),
            ]);

            throw new VisionApiException(
                'Direct AI response envelope JSON parse failed: ' . $e->getMessage(),
                'json_parse_error',
                $statusCode ?? 0,
                $e
            );
        }
    }

    private function sanitizeApiErrorBody(string $body): string
    {
        $sanitized = preg_replace(
            '/Bearer\s+[A-Za-z0-9_\-.]+/',
            'Bearer [REDACTED]',
            $body
        );

        if (!is_string($sanitized)) {
            $sanitized = $body;
        }

        $sanitized = preg_replace(
            '/("Authorization"\s*:\s*")[^"]*(")/i',
            '$1[REDACTED]$2',
            $sanitized
        );

        return is_string($sanitized) ? $sanitized : $body;
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
        $extractionStatus = $extraction['status'] ?? '';

        $isSuccess = $extractionStatus === 'SUCCESS';
        $isPartial = $extractionStatus === 'PARTIAL';

        if (!$isSuccess && !$isPartial) {
            return ['usable' => false, 'reason' => "Extraction status not usable: {$extractionStatus}"];
        }

        $confidence = $extraction['confidence'] ?? 0;
        if (!is_numeric($confidence) || $confidence < 0 || $confidence > 1) {
            return ['usable' => false, 'reason' => 'Invalid extraction confidence'];
        }

        if (!is_array($data['extractedAnswers'] ?? null) || !is_array($data['extractedQuestions'] ?? null)) {
            return ['usable' => false, 'reason' => 'extractedAnswers and extractedQuestions must be arrays'];
        }

        $hasValidAnswer = false;
        foreach ($data['extractedAnswers'] ?? [] as $idx => $answer) {
            if (!is_array($answer) || !array_key_exists('questionNumber', $answer) || !array_key_exists('answerText', $answer)) {
                return ['usable' => false, 'reason' => "Invalid extractedAnswer at index {$idx}"];
            }
            if ($answer['questionNumber'] !== null && !is_string($answer['questionNumber']) && !is_int($answer['questionNumber']) && !is_float($answer['questionNumber'])) {
                return ['usable' => false, 'reason' => "Invalid questionNumber in extractedAnswer at index {$idx}"];
            }
            if ($answer['answerText'] !== null && !is_string($answer['answerText'])) {
                return ['usable' => false, 'reason' => "Invalid answerText in extractedAnswer at index {$idx}"];
            }
            foreach (['page', 'sourcePage'] as $field) {
                if (array_key_exists($field, $answer) && $answer[$field] !== null && !is_numeric($answer[$field])) {
                    return ['usable' => false, 'reason' => "Invalid {$field} in extractedAnswer at index {$idx}"];
                }
            }
            $conf = $answer['confidence'] ?? null;
            if ($conf !== null && (!is_numeric($conf) || $conf < 0 || $conf > 1)) {
                return ['usable' => false, 'reason' => "Invalid confidence in extractedAnswer at index {$idx}"];
            }
            $hasValidAnswer = true;
        }

        $hasValidQuestion = false;
        foreach ($data['extractedQuestions'] ?? [] as $idx => $question) {
            if (!is_array($question) || !array_key_exists('questionNumber', $question) || !array_key_exists('questionText', $question)) {
                return ['usable' => false, 'reason' => "Invalid extractedQuestion at index {$idx}"];
            }
            if ($question['questionNumber'] !== null && !is_string($question['questionNumber']) && !is_int($question['questionNumber']) && !is_float($question['questionNumber'])) {
                return ['usable' => false, 'reason' => "Invalid questionNumber in extractedQuestion at index {$idx}"];
            }
            if ($question['questionText'] !== null && !is_string($question['questionText'])) {
                return ['usable' => false, 'reason' => "Invalid questionText in extractedQuestion at index {$idx}"];
            }
            foreach (['subQuestion', 'rawText', 'language'] as $field) {
                if (array_key_exists($field, $question) && $question[$field] !== null && !is_string($question[$field])) {
                    return ['usable' => false, 'reason' => "Invalid {$field} in extractedQuestion at index {$idx}"];
                }
            }
            $conf = $question['confidence'] ?? null;
            if ($conf !== null && (!is_numeric($conf) || $conf < 0 || $conf > 1)) {
                return ['usable' => false, 'reason' => "Invalid confidence in extractedQuestion at index {$idx}"];
            }
            $marks = $question['marks'] ?? null;
            if ($marks !== null && (!is_numeric($marks) || $marks < 0)) {
                return ['usable' => false, 'reason' => "Invalid marks in extractedQuestion at index {$idx}"];
            }
            $hasValidQuestion = true;
        }

        if ($isPartial) {
            $hasAnswersOrQuestions = $hasValidAnswer || $hasValidQuestion;
            $hasCurrentExamAnalysis = isset($data['currentExamAnalysis']) && is_array($data['currentExamAnalysis']);

            if (!$hasAnswersOrQuestions || !$hasCurrentExamAnalysis) {
                return ['usable' => false, 'reason' => 'PARTIAL extraction requires at least one valid answer/question AND currentExamAnalysis'];
            }
        }

        return ['usable' => true, 'reason' => 'Valid extraction'];
    }

    protected function validateAnalysis(array $data, DirectExtractionResult $extractionResult): array
    {
        if (!array_key_exists('currentExamAnalysis', $data)) {
            return ['usable' => false, 'reason' => 'Missing currentExamAnalysis'];
        }

        $analysis = $data['currentExamAnalysis'] ?? [];
        if (!is_array($analysis)) {
            return ['usable' => false, 'reason' => 'currentExamAnalysis must be an array'];
        }

        $requiredArrays = ['strengths', 'weaknesses', 'needsAttention', 'conceptGaps', 'recommendations', 'conceptAssessments', 'mistakes', 'evidence'];
        foreach ($requiredArrays as $key) {
            if (!array_key_exists($key, $analysis) || !is_array($analysis[$key] ?? [])) {
                return ['usable' => false, 'reason' => "Missing or invalid array: {$key}"];
            }
        }

        $allowedStatuses = ['STRONG', 'DEVELOPING', 'NEEDS_ATTENTION', 'INSUFFICIENT_EVIDENCE', 'UNANSWERED'];
        $allowedEvaluationStatuses = [
            'CORRECT', 'PARTIALLY_CORRECT', 'INCORRECT', 'UNANSWERED',
            'INSUFFICIENT_EVIDENCE', 'NOT_DETERMINABLE', 'UNDETERMINED',
            'ASSESSED_BY_VISIBLE_MARKING_ONLY', 'MARKED_CORRECT',
        ];
        $allowedCorrectness = ['CORRECT', 'INCORRECT', 'PARTIALLY_CORRECT', 'UNDETERMINED', 'NOT_DETERMINABLE', 'INSUFFICIENT_EVIDENCE', 'UNANSWERED'];
        foreach ($analysis['conceptAssessments'] ?? [] as $idx => $concept) {
            if (!is_array($concept) || !array_key_exists('status', $concept) || !array_key_exists('concept', $concept)) {
                return ['usable' => false, 'reason' => "Invalid conceptAssessment at index {$idx}"];
            }
            $status = $concept['status'] ?? null;
            if ($status !== null && !in_array($status, $allowedStatuses, true)) {
                return ['usable' => false, 'reason' => "Invalid concept status '{$concept['status']}' at index {$idx}"];
            }
            $conf = $concept['confidence'] ?? null;
            if ($conf !== null && (!is_numeric($conf) || $conf < 0 || $conf > 1)) {
                return ['usable' => false, 'reason' => "Invalid confidence in conceptAssessment at index {$idx}"];
            }
        }

        foreach ($analysis['mistakes'] ?? [] as $idx => $mistake) {
            if (!is_array($mistake) || !array_key_exists('questionNumber', $mistake) || !array_key_exists('type', $mistake) || !array_key_exists('description', $mistake)) {
                return ['usable' => false, 'reason' => "Invalid mistake at index {$idx}"];
            }
            $conf = $mistake['confidence'] ?? null;
            if ($conf !== null && (!is_numeric($conf) || $conf < 0 || $conf > 1)) {
                return ['usable' => false, 'reason' => "Invalid confidence in mistake at index {$idx}"];
            }
        }

        foreach ($analysis['evidence'] ?? [] as $idx => $evidence) {
            if (!is_array($evidence) || !array_key_exists('type', $evidence) || !array_key_exists('questionNumber', $evidence) || !array_key_exists('reason', $evidence)) {
                return ['usable' => false, 'reason' => "Invalid evidence reference at index {$idx}"];
            }
        }

        if (isset($data['questionAnalysis']) && is_array($data['questionAnalysis'])) {
            foreach ($data['questionAnalysis'] as $idx => $qa) {
                if (!is_array($qa) || !array_key_exists('questionNumber', $qa) || !array_key_exists('status', $qa) || !array_key_exists('evaluation', $qa) || !array_key_exists('concept', $qa) || !array_key_exists('improvement', $qa)) {
                    return ['usable' => false, 'reason' => "Invalid questionAnalysis at index {$idx}"];
                }
                $qaStatus = $qa['status'] ?? null;
                if ($qaStatus !== null && !in_array($qaStatus, $allowedStatuses, true)) {
                    return ['usable' => false, 'reason' => "Invalid questionAnalysis status '{$qaStatus}' at index {$idx}"];
                }

                $evaluation = $qa['evaluation'] ?? [];
                if (!is_array($evaluation) || !array_key_exists('evaluationStatus', $evaluation) || !array_key_exists('correctness', $evaluation)) {
                    return ['usable' => false, 'reason' => "Invalid evaluation in questionAnalysis at index {$idx}"];
                }
                $evalStatus = $evaluation['evaluationStatus'] ?? null;
                if ($evalStatus !== null && !in_array($evalStatus, $allowedEvaluationStatuses, true)) {
                    return ['usable' => false, 'reason' => "Invalid evaluationStatus '{$evalStatus}' in questionAnalysis at index {$idx}"];
                }
                $correctness = $evaluation['correctness'] ?? null;
                if ($correctness !== null && !is_string($correctness)) {
                    return ['usable' => false, 'reason' => "Invalid correctness type in questionAnalysis at index {$idx}"];
                }
                if ($correctness !== null && !in_array($correctness, $allowedCorrectness, true)) {
                    return ['usable' => false, 'reason' => "Invalid correctness '{$correctness}' in questionAnalysis at index {$idx}"];
                }
                foreach (['studentAnswer', 'questionText', 'feedback', 'reason'] as $field) {
                    if (array_key_exists($field, $evaluation) && $evaluation[$field] !== null && !is_string($evaluation[$field])) {
                        return ['usable' => false, 'reason' => "Invalid {$field} in evaluation at index {$idx}"];
                    }
                }
                $marksAwarded = $evaluation['marksAwarded'] ?? null;
                if ($marksAwarded !== null && (!is_numeric($marksAwarded) || $marksAwarded < 0)) {
                    return ['usable' => false, 'reason' => "Invalid marksAwarded in questionAnalysis at index {$idx}"];
                }
                $maxMarks = $evaluation['maxMarks'] ?? null;
                if ($maxMarks !== null && (!is_numeric($maxMarks) || $maxMarks < 0)) {
                    return ['usable' => false, 'reason' => "Invalid maxMarks in questionAnalysis at index {$idx}"];
                }

                $concept = $qa['concept'] ?? [];
                if (!is_array($concept) || !array_key_exists('name', $concept) || !array_key_exists('status', $concept)) {
                    return ['usable' => false, 'reason' => "Invalid concept in questionAnalysis at index {$idx}"];
                }
                $conceptStatus = $concept['status'] ?? null;
                if ($conceptStatus !== null && !in_array($conceptStatus, $allowedStatuses, true)) {
                    return ['usable' => false, 'reason' => "Invalid concept status '{$conceptStatus}' in questionAnalysis at index {$idx}"];
                }

                $improvement = $qa['improvement'] ?? [];
                if (!is_array($improvement) || !array_key_exists('whatToLearn', $improvement) || !array_key_exists('whatToPractice', $improvement) || !array_key_exists('recommendedAction', $improvement)) {
                    return ['usable' => false, 'reason' => "Invalid improvement in questionAnalysis at index {$idx}"];
                }
                foreach (['whatToLearn', 'whatToPractice', 'recommendedAction'] as $field) {
                    if ($improvement[$field] !== null && !is_string($improvement[$field])) {
                        return ['usable' => false, 'reason' => "Invalid {$field} in improvement at index {$idx}"];
                    }
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
                questionNumber: $ans['questionNumber'] ?? null,
                answerText: $ans['answerText'] ?? null,
                page: $ans['page'] ?? null,
                confidence: $ans['confidence'] ?? null,
                sourcePage: $ans['sourcePage'] ?? null,
            );
        }

        $extractedQuestions = [];
        foreach ($data['extractedQuestions'] ?? [] as $q) {
            $extractedQuestions[] = [
                'questionNumber' => $q['questionNumber'] ?? null,
                'subQuestion' => $q['subQuestion'] ?? null,
                'questionText' => $q['questionText'] ?? null,
                'marks' => $q['marks'] ?? null,
                'rawText' => $q['rawText'] ?? null,
                'language' => $q['language'] ?? null,
                'confidence' => $q['confidence'] ?? null,
                'questionKnownFromPaper' => $q['questionKnownFromPaper'] ?? null,
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
        $analysis = $data['currentExamAnalysis'] ?? [];

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
                concept: $ca['concept'] ?? '',
                status: $ca['status'] ?? 'INSUFFICIENT_EVIDENCE',
                confidence: $ca['confidence'] ?? 0.5,
                evidence: $ca['evidence'] ?? [],
            );
        }

        $mistakes = [];
        foreach ($analysis['mistakes'] ?? [] as $m) {
            $mistakes[] = new \App\DTO\Analysis\Direct\Mistake(
                questionNumber: $m['questionNumber'] ?? '',
                subQuestion: $m['subQuestion'] ?? null,
                type: $m['type'] ?? 'UNKNOWN',
                description: $m['description'] ?? '',
                confidence: $m['confidence'] ?? 0.5,
            );
        }

        $evidence = [];
        foreach ($analysis['evidence'] ?? [] as $e) {
            $evidence[] = new \App\DTO\Analysis\Direct\EvidenceReference(
                type: $e['type'] ?? 'answer',
                questionNumber: $e['questionNumber'] ?? '',
                subQuestion: $e['subQuestion'] ?? null,
                reason: $e['reason'] ?? '',
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

        // Helper for numeric optional fields (totalMarks, scoredMarks) that may be int or string
        $mergeNumeric = function (?string $key, $trustedValue, $extractedValue): ?\App\Service\Analysis\Processing\ProvenanceValue {
            $trustedValue = $trustedValue !== null ? (string)$trustedValue : null;
            $extractedValue = $extractedValue !== null ? (string)$extractedValue : null;
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
            totalMarks: $mergeNumeric('totalMarks', $trusted['totalMarks'] ?? null, $extracted['totalMarks'] ?? null),
            scoredMarks: $mergeNumeric('scoredMarks', $trusted['scoredMarks'] ?? null, $extracted['scoredMarks'] ?? null),
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
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    /**
     * Perform cost safety checks before making the OpenAI request.
     */
    private function performCostCheck(
        array $answerSheetPaths,
        ?string $questionPaperPath,
        int $totalFileSize,
        int $totalPageCount
    ): void {
        $maxPdfPages = $this->analysisSettings->getDirectAiMaxPdfPages();
        $maxFileSize = $this->analysisSettings->getUploadMaxFileSize();
        $maxOutputTokens = $this->analysisSettings->getDirectAiMaxOutputTokens();

        // Check max PDF pages
        if ($totalPageCount > $maxPdfPages) {
            $this->logger->error('Cost safety check failed: PDF page count exceeds limit', [
                'total_page_count' => $totalPageCount,
                'max_pdf_pages' => $maxPdfPages,
            ]);
            throw new \RuntimeException(
                sprintf('PDF page count (%d) exceeds maximum allowed (%d)', $totalPageCount, $maxPdfPages)
            );
        }

        // Check max file size
        if ($totalFileSize > $maxFileSize) {
            $this->logger->error('Cost safety check failed: Total file size exceeds limit', [
                'total_file_size' => $totalFileSize,
                'max_file_size' => $maxFileSize,
            ]);
            throw new \RuntimeException(
                sprintf('Total file size (%d bytes) exceeds maximum allowed (%d bytes)', $totalFileSize, $maxFileSize)
            );
        }

        // maxOutputTokens is optional. When null, the provider intentionally omits
        // max_output_tokens from the Responses API request.

        $this->logger->info('Cost safety check passed', [
            'total_page_count' => $totalPageCount,
            'max_pdf_pages' => $maxPdfPages,
            'total_file_size' => $totalFileSize,
            'max_file_size' => $maxFileSize,
            'max_output_tokens' => $maxOutputTokens,
        ]);
    }

    /**
     * Get PDF page count using pdftoppm or ImageMagick.
     */
        private function getPdfPageCount(string $filePath): int
    {
        $methods = [
            'pdftoppm' => function () use ($filePath): ?int {
                if (!$this->commandExists('pdftoppm')) {
                    return null;
                }
                $command = sprintf('pdftoppm -png "%s" /tmp/page_count_ 2>&1', $filePath);
                $output = [];
                $result = null;
                exec($command, $output, $result);
                if ($result === 0) {
                    $files = glob('/tmp/page_count_*.png');
                    if (!empty($files)) {
                        foreach ($files as $f) @unlink($f);
                        return count($files);
                    }
                }
                return null;
            },
            'identify' => function () use ($filePath): ?int {
                if (!$this->commandExists('identify')) {
                    return null;
                }
                $command = sprintf('identify -format "%%n" "%s" 2>&1', $filePath);
                $output = [];
                $result = null;
                exec($command, $output, $result);
                if ($result === 0 && !empty($output[0])) {
                    return (int) $output[0];
                }
                return null;
            },
            'magick' => function () use ($filePath): ?int {
                if (!$this->commandExists('magick')) {
                    return null;
                }
                $command = sprintf('magick identify -format "%%n" "%s" 2>&1', $filePath);
                $output = [];
                $result = null;
                exec($command, $output, $result);
                if ($result === 0 && !empty($output[0])) {
                    return (int) $output[0];
                }
                return null;
            },
            'gs' => function () use ($filePath): ?int {
                if (!$this->commandExists('gs')) {
                    return null;
                }
                $command = sprintf('gs -q -dNODISPLAY -c "(%s) (r) file runpdfbegin pdfpagecount = quit" 2>&1', $filePath);
                $output = [];
                $result = null;
                exec($command, $output, $result);
                if ($result === 0 && !empty($output[0]) && is_numeric($output[0])) {
                    return (int) $output[0];
                }
                return null;
            },
            'gswin64c' => function () use ($filePath): ?int {
                if (!$this->commandExists('gswin64c')) {
                    return null;
                }
                $command = sprintf('gswin64c -q -dNODISPLAY -c "(%s) (r) file runpdfbegin pdfpagecount = quit" 2>&1', $filePath);
                $output = [];
                $result = null;
                exec($command, $output, $result);
                if ($result === 0 && !empty($output[0]) && is_numeric($output[0])) {
                    return (int) $output[0];
                }
                return null;
            },
        ];

        foreach ($methods as $name => $method) {
            try {
                $count = $method();
                if ($count !== null && $count > 0) {
                    $this->logger->debug('PDF page count determined', [
                        'method' => $name,
                        'page_count' => $count,
                        'file' => $filePath,
                    ]);
                    return $count;
                }
            } catch (\Throwable $e) {
                $this->logger->debug('PDF page count method failed', [
                    'method' => $name,
                    'file' => $filePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->warning('Could not determine PDF page count using any available tool, assuming 1 page', [
            'file' => $filePath,
            'available_tools' => array_keys($methods),
        ]);
        return 1;
    }

    /**
     * Check if a command exists in PATH.
     */
    private function commandExists(string $command): bool
    {
        $output = [];
        $result = null;
        exec("where {$command} 2>nul", $output, $result); // Windows
        if ($result === 0) {
            return true;
        }
        exec("which {$command} 2>/dev/null", $output, $result); // Unix
        return $result === 0;
    }

    /**
     * Log usage and calculate estimated cost after successful API call.
     */
    private function logUsageAndCost(array $response, array $trustedMetadata): void
    {
        if (!isset($response['usage']) || !is_array($response['usage'])) {
            $this->logger->warning('No usage data in OpenAI response', [
                'analysis_id' => $trustedMetadata['analysisId'] ?? 'unknown',
            ]);
            return;
        }

        $usage = $response['usage'];
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);
        $totalTokens = (int) ($usage['total_tokens'] ?? 0);
        $cachedTokens = (int) ($usage['cached_tokens'] ?? 0);

        // Get model pricing from centralized settings
        $pricing = $this->analysisSettings->getModelPricing($this->extractionModel) ?? ['input' => 0.15, 'output' => 0.60];
        $inputPricePerMillion = (float) $pricing['input'];
        $outputPricePerMillion = (float) $pricing['output'];

        // Calculate estimated cost in USD
        $inputCost = ($inputTokens / 1_000_000) * $inputPricePerMillion;
        $outputCost = ($outputTokens / 1_000_000) * $outputPricePerMillion;
        $totalCost = $inputCost + $outputCost;

        $analysisId = $trustedMetadata['analysisId'] ?? 'unknown';
        $attemptId = $trustedMetadata['attemptId'] ?? 0;

        $this->logger->info('OpenAI Direct AI usage and cost', [
            'analysis_id' => $analysisId,
            'attempt_id' => $attemptId,
            'model' => $this->extractionModel,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'cached_tokens' => $cachedTokens,
            'input_cost_usd' => round($inputCost, 6),
            'output_cost_usd' => round($outputCost, 6),
            'total_estimated_cost_usd' => round($totalCost, 6),
            'pricing_input_per_million' => $inputPricePerMillion,
            'pricing_output_per_million' => $outputPricePerMillion,
        ]);
    }

    private function isModelWithoutTemperature(string $model): bool
    {
        foreach (self::MODELS_WITHOUT_TEMPERATURE as $unsupportedModel) {
            if (stripos($model, $unsupportedModel) !== false) {
                return true;
            }
        }
        return false;
    }
}