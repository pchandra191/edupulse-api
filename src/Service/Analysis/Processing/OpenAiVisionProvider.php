<?php
declare(strict_types=1);

namespace App\Service\Analysis\Processing;

use App\Exception\PdfRendererUnavailableException;
use App\Exception\VisionApiException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;

class OpenAiVisionProvider implements VisionOCRServiceInterface
{
    private const VISION_PROMPT = <<<'PROMPT'
You are an expert exam evaluator. You will receive images of a student's handwritten answer sheet and optionally the question paper.

Your task is to:
1. EXTRACT the student's handwritten answers from the answer sheet (visual understanding)
2. EVALUATE each answer against the question paper (if provided) or based on the visible question text

The document may contain handwritten Hindi in Devanagari script, English, or mixed Hindi/English. Extract Hindi text in its original Devanagari form. Do not translate Hindi to English. Preserve mixed Hindi/English text, numbers, symbols, paragraph boundaries, question numbers, and answer boundaries. Do not invent unreadable text.

For each question found:
- Extract the COMPLETE student response including: final answer, calculations, working, formulas, examples, explanations, subquestion hierarchy (1.a, 1.b, etc.), multiple choice selections, crossed-out work (note as [crossed out: ...])
- If question paper is provided, evaluate correctness and award marks
- If question paper is NOT provided, still extract the answer but mark evaluation as NOT_EVALUATABLE

Return structured JSON only with this exact format:
{
  "pages": [
    {
      "pageNumber": 1,
      "text": "full extracted text from page",
      "questions": [
        {
          "questionNumber": "1",
          "subQuestion": null,
          "questionText": "question text if visible from answer sheet, otherwise null",
          "answerText": "student's COMPLETE answer text including working, formulas, subquestions, explanations",
          "marks": 5,
          "evaluation": {
            "resultStatus": "CORRECT|PARTIALLY_CORRECT|INCORRECT|UNANSWERED|NOT_EVALUATABLE",
            "marksAwarded": 5,
            "maxMarks": 5,
            "answerQuality": 0.9,
            "reasoningQuality": 0.85,
            "confidence": 0.88,
            "errorType": "NONE|CONCEPTUAL|CALCULATION|FORMULA|PROCEDURAL|MISINTERPRETATION|CARELESS|INCOMPLETE_REASONING|KNOWLEDGE_GAP|TIME_OR_ATTEMPT|UNKNOWN",
            "errorDescription": "description if applicable",
            "difficulty": "EASY|MEDIUM|HARD|UNKNOWN",
            "difficultyConfidence": 0.8,
            "topics": [{"name": "topic name", "confidence": 0.85}],
            "concepts": [{"name": "concept name", "confidence": 0.8}],
            "misconception": {"identified": false, "description": null, "confidence": null}
          },
          "questionKnownFromPaper": true
        }
      ],
      "metadata": {
        "studentName": "extracted name or null",
        "rollNumber": "extracted roll number or null",
        "subject": "extracted subject or null",
        "examTitle": "extracted exam title or null",
        "class": "extracted class or null",
        "section": "extracted section or null",
        "totalMarks": 30
      },
      "confidence": 0.85,
      "language": "hi|en|mixed"
    }
  ],
  "metadata": {
    "studentName": "extracted name or null",
    "rollNumber": "extracted roll number or null",
    "subject": "extracted subject or null",
    "examTitle": "extracted exam title or null",
    "class": "extracted class or null",
    "section": "extracted section or null",
    "totalMarks": 30
  },
  "confidence": 0.85,
  "extractionMethod": "OPENAI_VISION_COMBINED",
  "ocrMetrics": {
    "pagesDetected": 1,
    "pagesProcessed": 1,
    "pagesSkipped": 0,
    "ocrPagesProcessed": 1,
    "ocrCacheHits": 0,
    "ocrAttempts": 1,
    "ocrRetryCount": 0
  },
  "language": "en",
  "currentExamAnalysis": {
    "strengths": ["strength 1", "strength 2"],
    "weaknesses": ["weakness 1", "weakness 2"],
    "needsAttention": ["area needing attention"],
    "conceptGaps": ["concept gap 1"],
    "recommendations": ["recommendation 1"]
  }
}

If a question number is not clearly visible, use null.
If question text is not visible on answer sheet and no question paper provided, use null.
If no questions found on page, return empty array.
For "questionKnownFromPaper": true if question paper was provided and question matched, false if only from answer sheet.
PROMPT;

    private const VERSION = '1.0';
    private const PROVIDER = 'OPENAI_VISION_COMBINED';
    private const MAX_RETRIES = 1;

    private HttpClientInterface $httpClient;
    private string $apiKey;
    private string $model;
    private LoggerInterface $logger;
    private string $projectDir;
    private PdfPageRendererInterface $pdfRenderer;

    public function __construct(
        HttpClientInterface $httpClient,
        string $apiKey,
        string $model,
        LoggerInterface $logger,
        string $projectDir,
        PdfPageRendererInterface $pdfRenderer,
    ) {
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->logger = $logger;
        $this->projectDir = $projectDir;
        $this->pdfRenderer = $pdfRenderer;
    }

    public function processPageImage(string $filePath, int $pageNumber, ?string $fileHash = null): array
    {
        $fileHash = $fileHash ?? hash_file('sha256', $filePath);

        $this->logger->info('Starting OpenAI Vision for page image', [
            'file' => $filePath,
            'page' => $pageNumber,
            'file_hash' => $fileHash,
        ]);

        $ocrMetrics = [
            'pagesDetected' => 1,
            'pagesProcessed' => 0,
            'pagesSkipped' => 0,
            'ocrPagesProcessed' => 0,
            'ocrCacheHits' => 0,
            'ocrAttempts' => 0,
            'ocrRetryCount' => 0,
        ];

        $base64Image = null;

        try {
            $this->logger->debug('Processing image page with OpenAI Vision', [
                'page' => $pageNumber,
                'image' => $filePath,
            ]);

            $base64Image = $this->encodeImageToBase64($filePath);
            $response = $this->callVisionApi([$base64Image]);
            unset($base64Image);
            $parsed = $this->parseVisionResponse($response, $pageNumber);
            unset($response);

            $validationResult = $this->validateVisionResult($parsed);
            if (!$validationResult['usable']) {
                throw new \RuntimeException('OpenAI Vision result validation failed: ' . $validationResult['reason']);
            }

            $ocrMetrics['ocrPagesProcessed']++;
            $ocrMetrics['ocrAttempts']++;
            $ocrMetrics['pagesProcessed']++;

            $parsed['fromCache'] = false;

            return $this->normalizeVisionResult([$parsed], $ocrMetrics);
        } catch (VisionApiException $e) {
            $this->logger->error('OpenAI Vision API failed for image page', [
                'page' => $pageNumber,
                'error_type' => $e->getErrorType(),
                'error' => $e->getMessage(),
                'is_insufficient_quota' => $e->isInsufficientQuota(),
                'is_rate_limit' => $e->isRateLimit(),
            ]);

            $ocrMetrics['ocrAttempts']++;

            if ($e->isInsufficientQuota()) {
                throw $e;
            }

            $pageResult = [
                'pageNumber' => $pageNumber,
                'text' => '',
                'questions' => [],
                'metadata' => [],
                'confidence' => 0.0,
                'fromCache' => false,
            ];

            return $this->normalizeVisionResult([$pageResult], $ocrMetrics);
        } catch (\Throwable $e) {
            $this->logger->error('OpenAI Vision failed for image page', [
                'page' => $pageNumber,
                'error' => $e->getMessage(),
            ]);

            $ocrMetrics['ocrAttempts']++;

            $pageResult = [
                'pageNumber' => $pageNumber,
                'text' => '',
                'questions' => [],
                'metadata' => [],
                'confidence' => 0.0,
                'fromCache' => false,
            ];

            return $this->normalizeVisionResult([$pageResult], $ocrMetrics);
        } finally {
            if (isset($base64Image)) {
                unset($base64Image);
            }
            gc_collect_cycles();
        }
    }

    public function processPdf(string $filePath, string $fileHash, ?array $pagesToProcess = null): array
    {
        if (!$this->pdfRenderer->isAvailable()) {
            $this->logger->error('PDF renderer is not available', [
                'file' => $filePath,
                'file_hash' => $fileHash,
            ]);
            throw new PdfRendererUnavailableException(
                'Handwriting extraction is temporarily unavailable because PDF page rendering is not configured.'
            );
        }

        $this->logger->info('Starting OpenAI Vision for PDF', [
            'file' => $filePath,
            'file_hash' => $fileHash,
            'pages_to_process' => $pagesToProcess,
        ]);

        $totalPages = $this->pdfRenderer->getPageCount($filePath);

        if ($pagesToProcess === null) {
            $pagesToProcess = range(1, $totalPages);
        }

        $this->logger->info('Starting page visual extraction', [
            'file_hash' => $fileHash,
            'total_pages' => $totalPages,
            'pages_to_process' => $pagesToProcess,
        ]);

        $pageResults = [];
        $ocrMetrics = [
            'pagesDetected' => $totalPages,
            'pagesProcessed' => 0,
            'pagesSkipped' => 0,
            'pagesAttempted' => 0,
            'pagesFailed' => 0,
            'stoppedReason' => null,
            'ocrPagesProcessed' => 0,
            'ocrCacheHits' => 0,
            'ocrAttempts' => 0,
            'ocrRetryCount' => 0,
        ];

        $openAiUnavailable = false;

        foreach ($pagesToProcess as $pageNumber) {
            $renderedImagePath = null;
            $base64Image = null;

            try {
                if ($openAiUnavailable) {
                    $this->logger->info('Skipping remaining pages - OpenAI Vision unavailable', [
                        'page' => $pageNumber,
                        'reason' => 'Previous page indicated quota/rate-limit issue',
                    ]);
                    $ocrMetrics['pagesSkipped']++;
                    $pageResults[] = [
                        'pageNumber' => $pageNumber,
                        'text' => '',
                        'questions' => [],
                        'metadata' => [],
                        'confidence' => 0.0,
                        'fromCache' => false,
                    ];
                    continue;
                }

                $this->logger->debug('Rendering PDF page for OpenAI Vision', [
                    'page' => $pageNumber,
                ]);

                $renderedImagePath = $this->pdfRenderer->renderPage($filePath, $pageNumber, 150, 'jpeg');

                 $this->logger->debug('Processing page with OpenAI Vision', [
                     'page' => $pageNumber,
                     'image' => $renderedImagePath,
                 ]);

                $ocrMetrics['pagesAttempted']++;
                $base64Image = $this->encodeImageToBase64($renderedImagePath);
                $response = $this->callVisionApi([$base64Image]);
                $parsed = $this->parseVisionResponse($response, $pageNumber);
                unset($response);

                $validationResult = $this->validateVisionResult($parsed);
                if (!$validationResult['usable']) {
                    throw new \RuntimeException('OpenAI Vision result validation failed: ' . $validationResult['reason']);
                }

                $ocrMetrics['ocrPagesProcessed']++;
                $ocrMetrics['ocrAttempts']++;
                $ocrMetrics['pagesProcessed']++;

                $this->logger->info('OpenAI Vision page completed', [
                    'page' => $pageNumber,
                    'file_hash' => $fileHash,
                ]);

                $pageResults[] = array_merge($parsed, [
                    'fromCache' => false,
                ]);
             } catch (VisionApiException $e) {
                if ($e->isInsufficientQuota() || $e->isRateLimit()) {
                    $this->logger->error('OpenAI Vision unavailable (quota/rate-limit), stopping all further processing', [
                        'page' => $pageNumber,
                        'error_type' => $e->getErrorType(),
                        'error' => $e->getMessage(),
                    ]);
                    $openAiUnavailable = true;
                    $ocrMetrics['stoppedReason'] = $e->getErrorType();
                    $ocrMetrics['ocrAttempts']++;
                    $ocrMetrics['pagesFailed']++;
                    $pageResults[] = [
                        'pageNumber' => $pageNumber,
                        'text' => '',
                        'questions' => [],
                        'metadata' => [],
                        'confidence' => 0.0,
                        'fromCache' => false,
                    ];
                    continue;
                }

                $this->logger->error('OpenAI Vision failed for page', [
                    'page' => $pageNumber,
                    'error' => $e->getMessage(),
                ]);
                $ocrMetrics['ocrAttempts']++;
                $ocrMetrics['pagesFailed']++;
                $pageResults[] = [
                    'pageNumber' => $pageNumber,
                    'text' => '',
                    'questions' => [],
                    'metadata' => [],
                    'confidence' => 0.0,
                    'fromCache' => false,
                ];
             } catch (\Throwable $e) {
                 $this->logger->error('OpenAI Vision failed for page', [
                     'page' => $pageNumber,
                     'error' => $e->getMessage(),
                 ]);

                 $ocrMetrics['ocrAttempts']++;
                 $ocrMetrics['pagesFailed']++;
                 $pageResults[] = [
                    'pageNumber' => $pageNumber,
                    'text' => '',
                    'questions' => [],
                    'metadata' => [],
                    'confidence' => 0.0,
                    'fromCache' => false,
                ];
            } finally {
                if (isset($base64Image)) {
                    unset($base64Image);
                }
                if (isset($renderedImagePath) && file_exists($renderedImagePath)) {
                    @unlink($renderedImagePath);
                }
                $this->logger->debug('Page memory released', [
                    'page' => $pageNumber,
                    'file_hash' => $fileHash,
                ]);
                gc_collect_cycles();
            }
        }

        usort($pageResults, fn($a, $b) => ($a['pageNumber'] ?? 0) <=> ($b['pageNumber'] ?? 0));

        return $this->normalizeVisionResult($pageResults, $ocrMetrics);
    }

    public function supportsMimeType(string $mimeType): bool
    {
        return in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true);
    }

    /**
     * Process both answer sheet and question paper together for combined extraction + evaluation.
     * Returns the full combined result including evaluations and currentExamAnalysis.
     */
    public function processCombined(
        string $answerSheetPath,
        string $fileHash,
        ?string $questionPaperPath = null,
        ?array $answerSheetPagesToProcess = null,
        ?array $questionPaperPagesToProcess = null
    ): array
    {
        if (!$this->pdfRenderer->isAvailable()) {
            throw new PdfRendererUnavailableException(
                'Handwriting extraction is temporarily unavailable because PDF page rendering is not configured.'
            );
        }

        $this->logger->info('Starting OpenAI Vision combined processing (page-by-page)', [
            'answer_sheet' => $answerSheetPath,
            'question_paper' => $questionPaperPath,
            'file_hash' => $fileHash,
        ]);

        $answerSheetMimeType = $this->getMimeTypeFromExtension($answerSheetPath);
        $answerSheetTotalPages = $answerSheetMimeType === 'application/pdf'
            ? $this->pdfRenderer->getPageCount($answerSheetPath)
            : 1;

        if ($answerSheetPagesToProcess === null) {
            $answerSheetPagesToProcess = range(1, $answerSheetTotalPages);
        }

        $pageResults = [];
        $ocrMetrics = [
            'pagesDetected' => $answerSheetTotalPages,
            'pagesProcessed' => 0,
            'pagesSkipped' => 0,
            'pagesAttempted' => 0,
            'pagesFailed' => 0,
            'stoppedReason' => null,
            'ocrPagesProcessed' => 0,
            'ocrCacheHits' => 0,
            'ocrAttempts' => 0,
            'ocrRetryCount' => 0,
        ];

        $openAiUnavailable = false;

        $questionPaperMimeType = $questionPaperPath !== null
            ? $this->getMimeTypeFromExtension($questionPaperPath)
            : null;

        foreach ($answerSheetPagesToProcess as $pageNumber) {
            if ($openAiUnavailable) {
                $ocrMetrics['pagesSkipped']++;
                $pageResults[] = [
                    'pageNumber' => $pageNumber,
                    'text' => '',
                    'questions' => [],
                    'metadata' => [],
                    'confidence' => 0.0,
                    'fromCache' => false,
                ];
                continue;
            }

            $renderedImagePath = null;
            $base64Image = null;

            try {
                $this->logger->debug('Rendering answer sheet page for combined OpenAI Vision', [
                    'page' => $pageNumber,
                ]);

                if ($answerSheetMimeType === 'application/pdf') {
                    $renderedImagePath = $this->pdfRenderer->renderPage($answerSheetPath, $pageNumber, 150, 'jpeg');
                } else {
                    $renderedImagePath = $answerSheetPath;
                }

                 $base64Image = $this->encodeImageToBase64($renderedImagePath);

                $ocrMetrics['pagesAttempted']++;

                // Build image list with AT MOST 2 images: answer sheet page + first question paper page
                // Never accumulate all question paper pages in memory
                $imagesForRequest = [$base64Image];
                unset($base64Image);

                $qpRenderedPath = null;
                $qpBase64 = null;

                if ($questionPaperPath !== null) {
                    try {
                        if ($questionPaperMimeType === 'application/pdf') {
                            $qpRenderedPath = $this->pdfRenderer->renderPage($questionPaperPath, 1, 150, 'jpeg');
                        } else {
                            $qpRenderedPath = $questionPaperPath;
                        }
                        $qpBase64 = $this->encodeImageToBase64($qpRenderedPath);
                        $imagesForRequest[] = $qpBase64;
                        unset($qpBase64);
                    } finally {
                        if (isset($qpRenderedPath) && $qpRenderedPath !== $questionPaperPath && file_exists($qpRenderedPath)) {
                            @unlink($qpRenderedPath);
                        }
                    }
                }

                $response = $this->callVisionApi($imagesForRequest);
                unset($imagesForRequest);

                $parsed = $this->parseCombinedResponse($response);
                unset($response);

                $validationResult = $this->validateVisionResult($parsed);
                if (!$validationResult['usable']) {
                    throw new \RuntimeException('OpenAI Vision combined result validation failed: ' . $validationResult['reason']);
                }

                $ocrMetrics['ocrPagesProcessed']++;
                $ocrMetrics['ocrAttempts']++;
                $ocrMetrics['pagesProcessed']++;

                $this->logger->info('OpenAI Vision page completed', [
                    'page' => $pageNumber,
                    'file_hash' => $fileHash,
                ]);

                foreach ($parsed['pages'] ?? [] as $page) {
                    $pageResults[] = array_merge($page, ['fromCache' => false]);
                }
            } catch (VisionApiException $e) {
                if ($e->isInsufficientQuota()) {
                    $this->logger->error('OpenAI Vision unavailable (insufficient quota), re-throwing for caller to skip OpenAI retry', [
                        'page' => $pageNumber,
                        'error_type' => $e->getErrorType(),
                        'error' => $e->getMessage(),
                    ]);
                    $ocrMetrics['ocrAttempts']++;
                    throw $e;
                }
                if ($e->isRateLimit()) {
                    $this->logger->error('OpenAI Vision rate limit, stopping all further processing', [
                        'page' => $pageNumber,
                        'error_type' => $e->getErrorType(),
                    ]);
                    $openAiUnavailable = true;
                    $ocrMetrics['stoppedReason'] = $e->getErrorType();
                    $ocrMetrics['ocrAttempts']++;
                    $ocrMetrics['pagesFailed']++;
                    $pageResults[] = [
                        'pageNumber' => $pageNumber,
                        'text' => '',
                        'questions' => [],
                        'metadata' => [],
                        'confidence' => 0.0,
                        'fromCache' => false,
                    ];
                    continue;
                }

                $this->logger->error('OpenAI Vision combined failed for page', [
                    'page' => $pageNumber,
                    'error' => $e->getMessage(),
                ]);
                $ocrMetrics['ocrAttempts']++;
                $ocrMetrics['pagesFailed']++;
                $pageResults[] = [
                    'pageNumber' => $pageNumber,
                    'text' => '',
                    'questions' => [],
                    'metadata' => [],
                    'confidence' => 0.0,
                    'fromCache' => false,
                ];
            } catch (\Throwable $e) {
                $this->logger->error('OpenAI Vision combined processing failed for page', [
                    'page' => $pageNumber,
                    'error' => $e->getMessage(),
                ]);
                $ocrMetrics['ocrAttempts']++;
                $ocrMetrics['pagesFailed']++;
                $pageResults[] = [
                    'pageNumber' => $pageNumber,
                    'text' => '',
                    'questions' => [],
                    'metadata' => [],
                    'confidence' => 0.0,
                    'fromCache' => false,
                ];
            } finally {
                if (isset($imagesForRequest)) {
                    unset($imagesForRequest);
                }
                if (isset($renderedImagePath) && $renderedImagePath !== $answerSheetPath && file_exists($renderedImagePath)) {
                    @unlink($renderedImagePath);
                }
                $this->logger->debug('Page memory released', [
                    'page' => $pageNumber,
                    'file_hash' => $fileHash,
                ]);
                gc_collect_cycles();
            }
        }

        $currentExamAnalysis = [
            'strengths' => [],
            'weaknesses' => [],
            'needsAttention' => [],
            'conceptGaps' => [],
            'recommendations' => [],
        ];

        return [
            'pages' => $pageResults,
            'metadata' => [],
            'questions' => [],
            'confidence' => 0.0,
            'extractionMethod' => 'OPENAI_VISION_COMBINED',
            'ocrMetrics' => $ocrMetrics,
            'language' => 'en',
            'currentExamAnalysis' => $currentExamAnalysis,
        ];
    }

    /**
     * Parse the combined response from OpenAI Vision.
     */
    public function parseCombinedResponse(array $response): array
    {
        $content = $response['choices'][0]['message']['content'] ?? '';

        $jsonContent = $this->extractJson($content);

        $data = json_decode($jsonContent, true);

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid OpenAI Vision combined response format: not an array');
        }

        // Handle the combined format with pages, metadata, currentExamAnalysis
        if (isset($data['pages'])) {
            $normalizedPages = [];
            foreach ($data['pages'] as $page) {
                $normalizedPages[] = $this->normalizePage($page, $page['pageNumber'] ?? 1);
            }

            // Extract currentExamAnalysis if present
            $currentExamAnalysis = $data['currentExamAnalysis'] ?? [
                'strengths' => [],
                'weaknesses' => [],
                'needsAttention' => [],
                'conceptGaps' => [],
                'recommendations' => [],
            ];

            return [
                'pages' => $normalizedPages,
                'metadata' => $data['metadata'] ?? [],
                'confidence' => $data['confidence'] ?? 0.5,
                'extractionMethod' => 'OPENAI_VISION_COMBINED',
                'ocrMetrics' => $data['ocrMetrics'] ?? [],
                'language' => $data['language'] ?? 'en',
                'currentExamAnalysis' => $currentExamAnalysis,
            ];
        }

        throw new \RuntimeException('OpenAI Vision combined response missing pages array');
    }

    public function callVisionApi(array $base64Images): array
    {
        $content = [
            [
                'type' => 'text',
                'text' => self::VISION_PROMPT,
            ],
        ];

        foreach ($base64Images as $base64Image) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:image/png;base64,' . $base64Image,
                ],
            ];
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => 8000,
            'stream' => false,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
        ];

        unset($content);

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 90,
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
                    $errorType === 'insufficient_quota' => 'OpenAI Vision is currently unavailable because the API account has insufficient credits. Please try again later or upload another answer sheet.',
                    $errorType === 'rate_limit' => 'OpenAI Vision rate limit exceeded. Please try again later.',
                    default => 'OpenAI Vision API returned HTTP 429.',
                };

                $this->logger->error('OpenAI Vision API returned 429', [
                    'status_code' => $statusCode,
                    'error_type' => $errorType,
                    'response_body' => $sanitizedContent,
                ]);

                throw new VisionApiException($errorMessage, $errorType);
            }

            if ($statusCode !== 200) {
                $sanitizedContent = preg_replace('/Bearer\s+[A-Za-z0-9_\-.]+/', 'Bearer [REDACTED]', $content);
                $sanitizedContent = preg_replace('/"Authorization"\s*:\s*"[^"]*"/', '"Authorization":"[REDACTED]"', $sanitizedContent);

                $this->logger->error('OpenAI Vision API returned non-200 status', [
                    'status_code' => $statusCode,
                    'response_body' => $sanitizedContent,
                ]);

                throw new VisionApiException("OpenAI Vision API returned status {$statusCode}: {$sanitizedContent}", null);
            }

            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (VisionApiException $e) {
            throw $e;
        } catch (TransportExceptionInterface $e) {
            throw new VisionApiException('OpenAI Vision API connection failed: ' . $e->getMessage(), null, $e);
        } catch (ClientExceptionInterface|ServerExceptionInterface|RedirectionExceptionInterface $e) {
            throw new VisionApiException('OpenAI Vision API error: ' . $e->getMessage(), null, $e);
        }
    }

    public function parseVisionResponse(array $response, int $pageNumber): array
    {
        $content = $response['choices'][0]['message']['content'] ?? '';

        $jsonContent = $this->extractJson($content);

        $data = json_decode($jsonContent, true);

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid OpenAI Vision response format: not an array');
        }

        $topLevelMetadata = $data['metadata'] ?? [];

        // Handle both single-page format (from processPageImage) and multi-page format (from processPdf)
        if (isset($data['pages'])) {
            // Multi-page format - find the page we're looking for
            foreach ($data['pages'] as $page) {
                if (($page['pageNumber'] ?? 0) === $pageNumber) {
                    $normalized = $this->normalizePage($page, $pageNumber);
                    $normalized['metadata'] = array_merge($topLevelMetadata, $normalized['metadata'] ?? []);
                    return $normalized;
                }
            }
            // Page not found, return empty
            return [
                'pageNumber' => $pageNumber,
                'text' => '',
                'questions' => [],
                'metadata' => $topLevelMetadata,
                'confidence' => 0.0,
                'language' => 'en',
            ];
        } else {
            // Single page format - normalize it
            $normalized = $this->normalizePage($data, $pageNumber);
            $normalized['metadata'] = array_merge($topLevelMetadata, $normalized['metadata'] ?? []);
            return $normalized;
        }
    }

    private function normalizePage(array $page, int $pageNumber): array
    {
        // Normalize questions to include evaluation data
        $questions = [];
        foreach ($page['questions'] ?? [] as $question) {
            $evaluation = $question['evaluation'] ?? [];
            $questions[] = [
                'questionNumber' => $question['questionNumber'] ?? '',
                'subQuestion' => $question['subQuestion'] ?? null,
                'questionText' => $question['questionText'] ?? '',
                'answerText' => $question['answerText'] ?? '',
                'marks' => $question['marks'] ?? null,
                'evaluation' => [
                    'resultStatus' => $evaluation['resultStatus'] ?? 'NOT_EVALUATABLE',
                    'marksAwarded' => $evaluation['marksAwarded'] ?? 0,
                    'maxMarks' => $evaluation['maxMarks'] ?? ($question['marks'] ?? 0),
                    'answerQuality' => $evaluation['answerQuality'] ?? 0.0,
                    'reasoningQuality' => $evaluation['reasoningQuality'] ?? 0.0,
                    'confidence' => $evaluation['confidence'] ?? 0.0,
                    'errorType' => $evaluation['errorType'] ?? 'UNKNOWN',
                    'errorDescription' => $evaluation['errorDescription'] ?? null,
                    'difficulty' => $evaluation['difficulty'] ?? 'UNKNOWN',
                    'difficultyConfidence' => $evaluation['difficultyConfidence'] ?? 0.0,
                    'topics' => $evaluation['topics'] ?? [],
                    'concepts' => $evaluation['concepts'] ?? [],
                    'misconception' => $evaluation['misconception'] ?? [
                        'identified' => false,
                        'description' => null,
                        'confidence' => null,
                    ],
                ],
                'questionKnownFromPaper' => $question['questionKnownFromPaper'] ?? false,
            ];
        }

        return [
            'pageNumber' => $page['pageNumber'] ?? $pageNumber,
            'text' => $page['text'] ?? '',
            'questions' => $questions,
            'metadata' => $page['metadata'] ?? [],
            'confidence' => $page['confidence'] ?? 0.5,
            'language' => $page['language'] ?? 'en',
        ];
    }

    public function validateVisionResult(array $parsed): array
    {
        $text = $parsed['text'] ?? '';
        $questions = $parsed['questions'] ?? [];

        if (strlen(trim($text)) < 10) {
            return ['usable' => false, 'reason' => 'OpenAI Vision response text too short or empty'];
        }

        $meaningfulAnswers = 0;
        foreach ($questions as $question) {
            $answerText = trim($question['answerText'] ?? '');
            if ($answerText !== '' && !$this->isPlaceholderText($answerText)) {
                $meaningfulAnswers++;
            }
        }

        $placeholderCount = 0;
        foreach ($questions as $question) {
            $answerText = trim($question['answerText'] ?? '');
            if ($answerText !== '' && $this->isPlaceholderText($answerText)) {
                $placeholderCount++;
            }
        }

        if ($placeholderCount > 0 && $meaningfulAnswers === 0) {
            return ['usable' => false, 'reason' => 'OpenAI Vision returned only placeholder answers'];
        }

        return ['usable' => true, 'reason' => 'Valid OpenAI Vision result'];
    }

    public function extractJson(string $content): string
    {
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
            return $matches[1];
        }

        if (preg_match('/(\{.*\})/s', $content, $matches)) {
            return $matches[1];
        }

        return $content;
    }

    public function normalizeVisionResult(array $pageResults, array $ocrMetrics): array
    {
        $allText = '';
        $allQuestions = [];
        $combinedMetadata = [
            'studentName' => null,
            'rollNumber' => null,
            'subject' => null,
            'examTitle' => null,
            'class' => null,
            'section' => null,
            'totalMarks' => null,
        ];
        $totalConfidence = 0.0;
        $validPages = 0;
        $languages = [];

        foreach ($pageResults as $page) {
            $allText .= "\n--- PAGE {$page['pageNumber']} ---\n" . ($page['text'] ?? '');

            foreach ($page['questions'] ?? [] as $question) {
                $allQuestions[] = [
                    'questionNumber' => $question['questionNumber'] ?? '',
                    'questionText' => $question['questionText'] ?? '',
                    'answerText' => $question['answerText'] ?? '',
                    'marks' => $question['marks'] ?? null,
                ];
            }

            foreach ($combinedMetadata as $key => $value) {
                if ($value === null && !empty($page['metadata'][$key] ?? null)) {
                    $combinedMetadata[$key] = $page['metadata'][$key];
                }
            }

            if (($page['confidence'] ?? 0) > 0) {
                $totalConfidence += $page['confidence'];
                $validPages++;
            }

            if (!empty($page['language'])) {
                $languages[] = $page['language'];
            }
        }

        $averageConfidence = $validPages > 0 ? $totalConfidence / $validPages : 0.0;

        $language = 'en';
        if (!empty($languages)) {
            $languageCounts = array_count_values($languages);
            arsort($languageCounts);
            $language = array_key_first($languageCounts);
            if (count($languageCounts) > 1) {
                $language = 'mixed';
            }
        }

        return [
            'pages' => $pageResults,
            'metadata' => $combinedMetadata,
            'questions' => $allQuestions,
            'confidence' => round($averageConfidence, 2),
            'extractionMethod' => 'OPENAI_VISION',
            'ocrMetrics' => $ocrMetrics,
            'language' => $language,
        ];
    }

    public function encodeImageToBase64(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'png') {
            $binaryData = file_get_contents($filePath);
            if ($binaryData === false) {
                throw new \RuntimeException('Failed to read image file: ' . $filePath);
            }

            $image = @imagecreatefromstring($binaryData);
            if ($image === false) {
                $encoded = base64_encode($binaryData);
                unset($binaryData);
                return $encoded;
            }

            ob_start();
            imagejpeg($image, null, 85);
            $jpegData = ob_get_clean();
            imagedestroy($image);
            unset($binaryData);

            if ($jpegData === false) {
                throw new \RuntimeException('Failed to convert image to JPEG: ' . $filePath);
            }

            return base64_encode($jpegData);
        }

        $binaryData = file_get_contents($filePath);
        if ($binaryData === false) {
            throw new \RuntimeException('Failed to read image file: ' . $filePath);
        }
        return base64_encode($binaryData);
    }

public function isPlaceholderText(string $text): bool
    {
        $placeholders = [
            '/^\(\d+\s*marks?\)$/i',
            '/^question\s*\d+$/i',
            '/^q\d+$/i',
            '/^answer\s*:?$/i',
            '/^working\s*:?$/i',
            '/^answer\s*\/\s*working$/i',
            '/^\d+\s*marks?$/i',
            '/^प्रश्न\s*\d+$/iu',
            '/^उत्तर\s*:?$/iu',
            '/^कार्य\s*:?$/iu',
            '/^\(\d+\s*अंक\)$/iu',
            '/^\d+\s*अंक$/iu',
            '/^अंक\s*\(\d+\)$/iu',
        ];

        $trimmed = trim($text);
        foreach ($placeholders as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return true;
            }
        }
        return false;
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