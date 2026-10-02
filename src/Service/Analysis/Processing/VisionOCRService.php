<?php

declare(strict_types=1);

namespace App\Service\Analysis\Processing;

use App\Document\OcrCache;
use App\Exception\PdfRendererUnavailableException;
use App\Exception\VisionApiException;
use App\Repository\MongoDB\OcrCacheRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;

readonly class VisionOCRService implements VisionOCRServiceInterface
{
    private const OCR_PROMPT = <<<'PROMPT'
You are extracting text from a student's answer sheet.

Read the page carefully in reading order.

The document may contain handwritten Hindi in Devanagari script. Extract Hindi text in its original Devanagari form. Do not translate Hindi to English. Preserve mixed Hindi/English text, numbers, symbols, paragraph boundaries, question numbers, and answer boundaries. Do not invent unreadable text.

Extract:
1. printed metadata (student name, roll number, subject, exam title, class, section, total marks)
2. question numbers
3. question text if visible
4. student's handwritten/printed answer
5. marks shown next to questions if visible.

Do not solve questions.
Do not correct spelling.
Do not infer missing text.
Do not invent content.

Preserve the student's written answer as accurately as possible.

Return structured JSON only with this exact format:
{
  "pageNumber": 1,
  "text": "full extracted text from page",
  "questions": [
    {
      "questionNumber": "1",
      "questionText": "question text if visible",
      "answerText": "student's answer text",
      "marks": 5
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
PROMPT;

    private const VERSION = '1.0';
    private const PROVIDER = 'VISION_OCR';
    private const MAX_RETRIES = 1;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private string $apiKey,
        private string $model,
        private LoggerInterface $logger,
        private string $projectDir,
        private OcrCacheRepository $ocrCacheRepository,
        private ?PdfPageRendererInterface $pdfRenderer = null,
    ) {
    }

    public function processPageImage(string $filePath, int $pageNumber, ?string $fileHash = null): array
    {
        $fileHash = $fileHash ?? hash_file('sha256', $filePath);

        $this->logger->info('Starting Vision OCR for page image', [
            'file' => $filePath,
            'page' => $pageNumber,
            'file_hash' => $fileHash,
        ]);

        $ocrMetrics = [
            'pagesDetected' => 1,
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

        $cacheKey = $this->generateCacheKey($fileHash, $pageNumber);

        $cachedResult = $this->getCachedResult($cacheKey);
        if ($cachedResult !== null && $cachedResult->isCompleted()) {
            $this->logger->info('OCR cache hit', [
                'file_hash' => $fileHash,
                'page' => $pageNumber,
            ]);

            $ocrMetrics['ocrCacheHits']++;
            $ocrMetrics['pagesProcessed']++;

            $cached = $cachedResult->getResult();
            $cached['fromCache'] = true;

            return $this->normalizeOcrResult([$cached], $ocrMetrics);
        }

        $pendingEntry = $this->getPendingCacheEntry($cacheKey);
        if ($pendingEntry !== null) {
            $this->logger->info('OCR already in progress for image page', [
                'file_hash' => $fileHash,
                'page' => $pageNumber,
            ]);
            $pendingEntry->incrementAttemptCount();
            $this->ocrCacheRepository->save($pendingEntry);
        }

        $cacheEntry = $this->createOrUpdateCacheEntry($cacheKey, $fileHash, $pageNumber);

        try {
            $this->logger->debug('Processing image page with Vision OCR', [
                'page' => $pageNumber,
                'image' => $filePath,
            ]);

             $base64Image = $this->encodeImageToBase64($filePath);
            $ocrMetrics['pagesAttempted'] = 1;
            $response = $this->callVisionApi($base64Image);
            unset($base64Image);
            $parsed = $this->parseOcrResponse($response, $pageNumber);
            unset($response);

            $validationResult = $this->validateOcrResult($parsed);
            if (!$validationResult['usable']) {
                throw new \RuntimeException('OCR result validation failed: ' . $validationResult['reason']);
            }

            $ocrMetrics['ocrPagesProcessed']++;
            $ocrMetrics['ocrAttempts']++;
            $ocrMetrics['pagesProcessed']++;

            $cacheEntry->markCompleted($parsed);
            $this->ocrCacheRepository->save($cacheEntry);

            $parsed['fromCache'] = false;

            return $this->normalizeOcrResult([$parsed], $ocrMetrics);
        } catch (VisionApiException $e) {
            $this->logger->error('Vision OCR API failed for image page', [
                'page' => $pageNumber,
                'error_type' => $e->getErrorType(),
                'error' => $e->getMessage(),
                'is_insufficient_quota' => $e->isInsufficientQuota(),
                'is_rate_limit' => $e->isRateLimit(),
            ]);

            $ocrMetrics['ocrAttempts']++;
            $ocrMetrics['pagesFailed']=1;

            $cacheEntry->markFailed($e->getMessage());
            $this->ocrCacheRepository->save($cacheEntry);

            $pageResult = [
                'pageNumber' => $pageNumber,
                'text' => '',
                'questions' => [],
                'metadata' => [],
                'confidence' => 0.0,
                'fromCache' => false,
            ];

            return $this->normalizeOcrResult([$pageResult], $ocrMetrics);
        } catch (\Throwable $e) {
            $this->logger->error('Vision OCR failed for image page', [
                'page' => $pageNumber,
                'error' => $e->getMessage(),
            ]);

            $ocrMetrics['ocrAttempts']++;
            $ocrMetrics['pagesFailed'] = 1;

            $cacheEntry->markFailed($e->getMessage());
            $this->ocrCacheRepository->save($cacheEntry);

            $pageResult = [
                'pageNumber' => $pageNumber,
                'text' => '',
                'questions' => [],
                'metadata' => [],
                'confidence' => 0.0,
                'fromCache' => false,
            ];

            return $this->normalizeOcrResult([$pageResult], $ocrMetrics);
        } finally {
            if (isset($base64Image)) {
                unset($base64Image);
            }
            $this->logger->debug('Page memory released', [
                'page' => $pageNumber,
                'file' => basename($filePath),
            ]);
            gc_collect_cycles();
        }
    }

    public function processPdf(string $filePath, string $fileHash, ?array $pagesToProcess = null): array
    {
        if ($this->pdfRenderer === null) {
            throw new PdfRendererUnavailableException(
                'Handwriting extraction is temporarily unavailable because PDF page rendering is not configured.'
            );
        }

        if (!$this->pdfRenderer->isAvailable()) {
            $this->logger->error('PDF renderer is not available', [
                'file' => $filePath,
                'file_hash' => $fileHash,
            ]);
            throw new PdfRendererUnavailableException(
                'Handwriting extraction is temporarily unavailable because PDF page rendering is not configured.'
            );
        }

        $this->logger->info('Starting Vision OCR for PDF with caching', [
            'file' => $filePath,
            'file_hash' => $fileHash,
            'pages_to_process' => $pagesToProcess,
        ]);

        $totalPages = $this->pdfRenderer->getPageCount($filePath);

        if ($pagesToProcess === null) {
            $pagesToProcess = range(1, $totalPages);
        }

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

        $visionUnavailable = false;

        $this->logger->info('Starting page visual extraction', [
            'file_hash' => $fileHash,
            'total_pages' => $totalPages,
            'pages_to_process' => $pagesToProcess,
        ]);

        foreach ($pagesToProcess as $pageNumber) {
            $cacheKey = $this->generateCacheKey($fileHash, $pageNumber);

            $cachedResult = $this->getCachedResult($cacheKey);
            if ($cachedResult !== null && $cachedResult->isCompleted()) {
                $this->logger->info('OCR cache hit', [
                    'file_hash' => $fileHash,
                    'page' => $pageNumber,
                ]);

                $ocrMetrics['ocrCacheHits']++;
                $ocrMetrics['pagesProcessed']++;

                $cached = $cachedResult->getResult();
                $cached['fromCache'] = true;
                $pageResults[] = $cached;
                continue;
            }

            if ($visionUnavailable) {
                $this->logger->info('Skipping remaining pages - Vision API unavailable', [
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

            $pendingEntry = $this->getPendingCacheEntry($cacheKey);
            if ($pendingEntry !== null) {
                $this->logger->info('OCR already in progress for page, waiting...', [
                    'file_hash' => $fileHash,
                    'page' => $pageNumber,
                ]);
                $pendingEntry->incrementAttemptCount();
                $this->ocrCacheRepository->save($pendingEntry);
            }

            $cacheEntry = $this->createOrUpdateCacheEntry($cacheKey, $fileHash, $pageNumber);

            $renderedImagePath = null;
            $base64Image = null;

            try {
                $this->logger->debug('Rendering PDF page for OCR', [
                    'page' => $pageNumber,
                ]);

                $renderedImagePath = $this->pdfRenderer->renderPage($filePath, $pageNumber, 150, 'jpeg');

                 $this->logger->debug('Processing page with Vision OCR', [
                     'page' => $pageNumber,
                     'image' => $renderedImagePath,
                 ]);

                $ocrMetrics['pagesAttempted']++;
                $base64Image = $this->encodeImageToBase64($renderedImagePath);
                $response = $this->callVisionApi($base64Image);
                unset($base64Image);
                $parsed = $this->parseOcrResponse($response, $pageNumber);
                unset($response);

                $validationResult = $this->validateOcrResult($parsed);
                if (!$validationResult['usable']) {
                    throw new \RuntimeException('OCR result validation failed: ' . $validationResult['reason']);
                }

                $ocrMetrics['ocrPagesProcessed']++;
                $ocrMetrics['ocrAttempts']++;
                $ocrMetrics['pagesProcessed']++;

                $this->logger->info('Vision OCR page completed', [
                    'page' => $pageNumber,
                    'file_hash' => $fileHash,
                ]);

                $cacheEntry->markCompleted($parsed);
                $this->ocrCacheRepository->save($cacheEntry);

                $pageResults[] = array_merge($parsed, [
                    'fromCache' => false,
                ]);
            } catch (VisionApiException $e) {
                if ($e->isInsufficientQuota() || $e->isRateLimit()) {
                    $this->logger->error('Vision API unavailable (quota/rate-limit), stopping all further processing', [
                        'page' => $pageNumber,
                        'error_type' => $e->getErrorType(),
                        'error' => $e->getMessage(),
                    ]);
                    $visionUnavailable = true;
                    $ocrMetrics['stoppedReason'] = $e->getErrorType();
                    $ocrMetrics['ocrAttempts']++;
                    $ocrMetrics['pagesFailed']++;
                    $cacheEntry->markFailed($e->getMessage());
                    $this->ocrCacheRepository->save($cacheEntry);
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

                $this->logger->error('Vision OCR failed for page', [
                    'page' => $pageNumber,
                    'error' => $e->getMessage(),
                ]);
                $ocrMetrics['ocrAttempts']++;
                $ocrMetrics['pagesFailed']++;

                if ($cacheEntry->canRetry()) {
                    $this->logger->info('Retrying OCR for page', [
                        'page' => $pageNumber,
                        'attempt' => $cacheEntry->getRetryCount() + 1,
                    ]);

                    $cacheEntry->incrementRetryCount();
                    $this->ocrCacheRepository->save($cacheEntry);

                    try {
                        $base64Image = $this->encodeImageToBase64($renderedImagePath);
                        $response = $this->callVisionApi($base64Image);
                        unset($base64Image);
                        $parsed = $this->parseOcrResponse($response, $pageNumber);
                        unset($response);

                        $validationResult = $this->validateOcrResult($parsed);
                        if (!$validationResult['usable']) {
                            throw new \RuntimeException('OCR retry validation failed: ' . $validationResult['reason']);
                        }

                        $ocrMetrics['ocrRetryCount']++;
                        $ocrMetrics['ocrPagesProcessed']++;
                        $ocrMetrics['pagesProcessed']++;

                        $cacheEntry->markCompleted($parsed);
                        $this->ocrCacheRepository->save($cacheEntry);

                        $pageResults[] = array_merge($parsed, [
                            'fromCache' => false,
                        ]);
                    } catch (VisionApiException $retryException) {
                        if ($retryException->isInsufficientQuota() || $retryException->isRateLimit()) {
                            $this->logger->error('Vision API unavailable on retry (quota/rate-limit), stopping', [
                                'error_type' => $retryException->getErrorType(),
                            ]);
                            $visionUnavailable = true;
                            $ocrMetrics['stoppedReason'] = $retryException->getErrorType();
                            $cacheEntry->markFailed($retryException->getMessage());
                            $this->ocrCacheRepository->save($cacheEntry);
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

                        $this->logger->error('OCR retry failed for page', [
                            'page' => $pageNumber,
                            'error' => $retryException->getMessage(),
                        ]);
                        $cacheEntry->markFailed($retryException->getMessage());
                        $this->ocrCacheRepository->save($cacheEntry);

                        $pageResults[] = [
                            'pageNumber' => $pageNumber,
                            'text' => '',
                            'questions' => [],
                            'metadata' => [],
                            'confidence' => 0.0,
                            'fromCache' => false,
                        ];
                    } catch (\Throwable $retryException) {
                        $this->logger->error('OCR retry failed for page', [
                            'page' => $pageNumber,
                            'error' => $retryException->getMessage(),
                        ]);
                        $cacheEntry->markFailed($retryException->getMessage());
                        $this->ocrCacheRepository->save($cacheEntry);
                        $ocrMetrics['pagesFailed']++;

                        $pageResults[] = [
                            'pageNumber' => $pageNumber,
                            'text' => '',
                            'questions' => [],
                            'metadata' => [],
                            'confidence' => 0.0,
                            'fromCache' => false,
                        ];
                    }
                    } else {
                    $cacheEntry->markFailed($e->getMessage());
                    $this->ocrCacheRepository->save($cacheEntry);

                    $pageResults[] = [
                        'pageNumber' => $pageNumber,
                        'text' => '',
                        'questions' => [],
                        'metadata' => [],
                        'confidence' => 0.0,
                        'fromCache' => false,
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->error('Vision OCR failed for page', [
                    'page' => $pageNumber,
                    'error' => $e->getMessage(),
                ]);

                $ocrMetrics['ocrAttempts']++;
                $ocrMetrics['pagesFailed']++;

                $cacheEntry->markFailed($e->getMessage());
                $this->ocrCacheRepository->save($cacheEntry);

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

        return $this->normalizeOcrResult($pageResults, $ocrMetrics);
    }

    public function supportsMimeType(string $mimeType): bool
    {
        return in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true);
    }

    private function callVisionApi(string $base64Image): array
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => 4000,
            'stream' => false,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => self::OCR_PROMPT,
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:image/png;base64,' . $base64Image,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        unset($base64Image);

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . '/chat/completions', [
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
                    $errorType === 'insufficient_quota' => 'Vision API is currently unavailable because the API account has insufficient credits. Please try again later or upload another answer sheet.',
                    $errorType === 'rate_limit' => 'Vision API rate limit exceeded. Please try again later.',
                    default => 'Vision API returned HTTP 429.',
                };

                throw new VisionApiException($errorMessage, $errorType);
            }

            if ($statusCode !== 200) {
                $sanitizedContent = preg_replace('/Bearer\s+[A-Za-z0-9_\-.]+/', 'Bearer [REDACTED]', $content);
                $sanitizedContent = preg_replace('/"Authorization"\s*:\s*"[^"]*"/', '"Authorization":"[REDACTED]"', $sanitizedContent);

                throw new VisionApiException("Vision API returned status {$statusCode}: {$sanitizedContent}", null);
            }

            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (VisionApiException $e) {
            throw $e;
        } catch (TransportExceptionInterface $e) {
            throw new VisionApiException('Vision API connection failed: ' . $e->getMessage(), null, $e);
        } catch (ClientExceptionInterface|ServerExceptionInterface|RedirectionExceptionInterface $e) {
            throw new VisionApiException('Vision API error: ' . $e->getMessage(), null, $e);
        }
    }

    private function parseOcrResponse(array $response, int $pageNumber): array
    {
            $content = $response['choices'][0]['message']['content'] ?? '';

            $jsonContent = $this->extractJson($content);

            $data = json_decode($jsonContent, true);

            if (!is_array($data)) {
                throw new \RuntimeException('Invalid OCR response format: not an array');
            }

            $normalized = [
                'pageNumber' => $pageNumber, // Always use the actual page number, not the provider's (provider bug: always returns 1)
                'text' => $data['text'] ?? '',
                'questions' => $data['questions'] ?? [],
                'metadata' => $data['metadata'] ?? [],
                'confidence' => $data['confidence'] ?? 0.5,
                'language' => $data['language'] ?? 'en',
            ];

            return $normalized;
    }

    private function validateOcrResult(array $parsed): array
    {
        $text = $parsed['text'] ?? '';
        $questions = $parsed['questions'] ?? [];

        if (strlen(trim($text)) < 10) {
            return ['usable' => false, 'reason' => 'OCR response text too short or empty'];
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
            return ['usable' => false, 'reason' => 'OCR returned only placeholder answers'];
        }

        return ['usable' => true, 'reason' => 'Valid OCR result'];
    }

    private function extractJson(string $content): string
    {
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
            return $matches[1];
        }

        if (preg_match('/(\{.*\})/s', $content, $matches)) {
            return $matches[1];
        }

        return $content;
    }

    private function normalizeOcrResult(array $pageResults, array $ocrMetrics): array
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
            'extractionMethod' => 'VISION_OCR',
            'ocrMetrics' => $ocrMetrics,
            'language' => $language,
        ];
    }

    private function encodeImageToBase64(string $filePath): string
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

    private function generateCacheKey(string $fileHash, int $pageNumber): string
    {
        return sprintf('%s:%d:%s:%s:%s', $fileHash, $pageNumber, self::PROVIDER, $this->model, self::VERSION);
    }

    private function getCachedResult(string $cacheKey): ?OcrCache
    {
        $cached = $this->ocrCacheRepository->findByCacheKey($cacheKey);
        if ($cached !== null && $cached->isCompleted()) {
            return $cached;
        }
        return null;
    }

    private function getPendingCacheEntry(string $cacheKey): ?OcrCache
    {
        $cached = $this->ocrCacheRepository->findByCacheKey($cacheKey);
        if ($cached !== null && in_array($cached->getStatus(), ['PENDING', 'PROCESSING'], true)) {
            return $cached;
        }
        return null;
    }

    private function createOrUpdateCacheEntry(string $cacheKey, string $fileHash, int $pageNumber): OcrCache
    {
        $existing = $this->ocrCacheRepository->findByCacheKey($cacheKey);
        if ($existing !== null) {
            $existing->setStatus('PROCESSING');
            $existing->incrementAttemptCount();
            $this->ocrCacheRepository->save($existing);
            return $existing;
        }

        $id = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        $entry = new OcrCache(
            $id,
            $cacheKey,
            $fileHash,
            $pageNumber,
            self::PROVIDER,
            $this->model,
            self::VERSION
        );
        $entry->setStatus('PROCESSING');
        $this->ocrCacheRepository->save($entry);
        return $entry;
    }

    private function isPlaceholderText(string $text): bool
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
}
