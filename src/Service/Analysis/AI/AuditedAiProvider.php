<?php

declare(strict_types=1);

namespace App\Service\Analysis\AI;

use App\Document\Analysis;
use App\DTO\Analysis\AIAnalysisResultDTO;
use Psr\Log\LoggerInterface;

/**
 * Wraps the AI provider with audit logging, PII stripping, and timing.
 * Logs every AI call for compliance and debugging.
 */
readonly class AuditedAiProvider
{
    public function __construct(
        private AiProviderInterface $provider,
        private PiiStripper $piiStripper,
        private AiAuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Evaluate a question with audit logging.
     *
     * @return array{result: QuestionEvaluationResult, piiStripped: array}
     */
    public function evaluateQuestion(QuestionEvaluationRequest $request, Analysis $analysis): array
    {
        $provider = $this->provider->getProviderName();
        $model = $this->provider->getModelName();

        // Strip PII from request fields
        $piiStripped = [];
        $sanitizedRequest = $request;

        $textFields = [
            'questionText' => $request->questionText,
            'studentAnswer' => $request->studentAnswer ?? '',
            'subject' => $request->subject ?? '',
        ];

        foreach ($textFields as $key => $text) {
            if ($text !== '' && $text !== null) {
                $result = $this->piiStripper->strip($text);
                if (!empty($result['stripped'])) {
                    $piiStripped[$key] = $result['stripped'];
                }
            }
        }

        // Build sanitized request for audit
        $auditRequest = [
            'questionNumber' => $request->questionNumber,
            'maxMarks' => $request->maxMarks,
            'questionType' => $request->questionType,
            'questionText' => $textFields['questionText'],
            'studentAnswer' => $textFields['studentAnswer'],
            'subject' => $textFields['subject'],
            'topics' => $request->topics,
            'concepts' => $request->concepts,
        ];

        $startTime = microtime(true);

        try {
            $result = $this->provider->evaluateQuestion($request);
            $durationMs = (microtime(true) - $startTime) * 1000;

            $this->auditLogger->logAiCall(
                $analysis,
                $provider,
                $model,
                $auditRequest,
                $this->resultToAuditArray($result),
                $durationMs,
                true,
                null,
                $piiStripped,
            );

            return ['result' => $result, 'piiStripped' => $piiStripped];
        } catch (\Throwable $e) {
            $durationMs = (microtime(true) - $startTime) * 1000;

            $this->auditLogger->logAiCall(
                $analysis,
                $provider,
                $model,
                $auditRequest,
                [],
                $durationMs,
                false,
                $e->getMessage(),
                $piiStripped,
            );

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resultToAuditArray(QuestionEvaluationResult $result): array
    {
        return [
            'questionNumber' => $result->questionNumber,
            'resultStatus' => $result->resultStatus->value,
            'marksAwarded' => $result->marksAwarded,
            'maxMarks' => $result->maxMarks,
            'answerQuality' => $result->answerQuality,
            'reasoningQuality' => $result->reasoningQuality,
            'confidence' => $result->confidence,
            'errorType' => $result->errorType->value,
            'errorDescription' => $result->errorDescription,
            'difficulty' => $result->difficulty->value,
            'difficultyConfidence' => $result->difficultyConfidence,
            'topics' => $result->topics,
            'concepts' => $result->concepts,
            'misconception' => [
                'identified' => $result->misconception->identified,
                'description' => $result->misconception->description,
                'confidence' => $result->misconception->confidence,
            ],
        ];
    }

    public function getProvider(): AiProviderInterface
    {
        return $this->provider;
    }
}