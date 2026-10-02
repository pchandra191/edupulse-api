<?php

declare(strict_types=1);

namespace App\Service\Analysis\AI;

use App\Document\Analysis;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

/**
 * Logs every AI provider call for audit, security, and compliance.
 * Stores structured audit records in PostgreSQL for 90-day retention.
 */
readonly class AiAuditLogger
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DocumentManager $documentManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $requestPayload
     * @param array<string, mixed> $responsePayload
     * @param array<string, mixed> $piiStripped
     */
    public function logAiCall(
        Analysis $analysis,
        string $provider,
        string $model,
        array $requestPayload,
        array $responsePayload,
        float $durationMs,
        bool $success,
        ?string $error = null,
        array $piiStripped = [],
    ): void {
        $auditRecord = [
            'analysisId' => $analysis->getAnalysisId(),
            'studentId' => $analysis->getStudentId(),
            'provider' => $provider,
            'model' => $model,
            'request' => $this->sanitizePayload($requestPayload),
            'response' => $this->sanitizePayload($responsePayload),
            'durationMs' => round($durationMs, 2),
            'success' => $success,
            'error' => $error,
            'piiStripped' => $piiStripped,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        // Log to application logger
        $this->logger->info('AI audit log', $auditRecord);

        // Persist to MongoDB for audit trail
        try {
            $this->persistAuditRecord($auditRecord);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to persist AI audit record', [
                'analysis_id' => $analysis->getAnalysisId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        // Truncate large payloads to avoid memory issues
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return ['error' => 'Failed to serialize payload'];
        }

        if (strlen($json) > 10000) {
            return ['truncated' => true, 'preview' => substr($json, 0, 10000)];
        }

        return $payload;
    }

    private function persistAuditRecord(array $record): void
    {
        $audit = new \App\Document\AiAuditLog();
        $audit->setAnalysisId($record['analysisId']);
        $audit->setStudentId($record['studentId']);
        $audit->setProvider($record['provider']);
        $audit->setModel($record['model']);
        $audit->setRequest($record['request']);
        $audit->setResponse($record['response']);
        $audit->setDurationMs($record['durationMs']);
        $audit->setSuccess($record['success']);
        $audit->setError($record['error']);
        $audit->setPiiStripped($record['piiStripped']);
        $audit->setTimestamp(new \DateTimeImmutable());

        $this->documentManager->persist($audit);
        $this->documentManager->flush();
    }
}