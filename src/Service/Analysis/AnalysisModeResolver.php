<?php

declare(strict_types=1);

namespace App\Service\Analysis;

use App\Service\Analysis\Processing\OpenAiDirectDocumentProvider;
use Psr\Log\LoggerInterface;

enum AnalysisAiMode: string
{
    case Direct = 'direct';
    case Legacy = 'legacy';

    public static function fromString(string $value): self
    {
        return match (strtolower($value)) {
            'direct' => self::Direct,
            'legacy' => self::Legacy,
            default => self::Legacy,
        };
    }
}

class AnalysisModeResolver
{
    public function __construct(
        private string $mode,
        private LoggerInterface $logger,
    ) {
    }

    public function getMode(): AnalysisAiMode
    {
        return AnalysisAiMode::fromString($this->mode);
    }

    public function isDirectMode(): bool
    {
        return $this->getMode() === AnalysisAiMode::Direct;
    }

    public function isLegacyMode(): bool
    {
        return $this->getMode() === AnalysisAiMode::Legacy;
    }
}