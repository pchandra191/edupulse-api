<?php

declare(strict_types=1);

namespace App\Service\Analysis\Settings;

readonly class AnalysisSettings
{
    /**
     * @param array{upload: array{max_total_pages: int, max_file_size: int, max_files: int}, direct_ai: array{model: string, pdf_detail: string, max_pdf_pages: int, max_output_tokens: int}, pricing: array<string, array{input: float, output: float}>} $settings
     */
    public function __construct(
        private array $settings,
    ) {
    }

    /**
     * Get upload settings
     *
     * @return array{max_total_pages: int, max_file_size: int, max_files: int}
     */
    public function getUpload(): array
    {
        return $this->settings['upload'];
    }

    /**
     * Get Direct AI settings
     *
     * @return array{model: string, pdf_detail: string, max_pdf_pages: int, max_output_tokens: int}
     */
    public function getDirectAi(): array
    {
        return $this->settings['direct_ai'];
    }

    /**
     * Get pricing settings
     *
     * @return array<string, array{input: float, output: float}>
     */
    public function getPricing(): array
    {
        return $this->settings['pricing'];
    }

    public function getUploadMaxTotalPages(): int
    {
        return $this->settings['upload']['max_total_pages'];
    }

    public function getUploadMaxFileSize(): int
    {
        return $this->settings['upload']['max_file_size'];
    }

    public function getUploadMaxFiles(): int
    {
        return $this->settings['upload']['max_files'];
    }

    public function getDirectAiModel(): string
    {
        return $this->settings['direct_ai']['model'];
    }

    public function getDirectAiPdfDetail(): string
    {
        return $this->settings['direct_ai']['pdf_detail'];
    }

    public function getDirectAiMaxPdfPages(): int
    {
        return $this->settings['direct_ai']['max_pdf_pages'];
    }

    public function getDirectAiMaxOutputTokens(): ?int
    {
        $value = $this->settings['direct_ai']['max_output_tokens'] ?? null;
        if ($value === '' || $value === null) {
            return null;
        }
        return (int) $value;
    }

    /**
     * Get pricing for a specific model
     *
     * @return array{input: float, output: float}|null
     */
    public function getModelPricing(string $model): ?array
    {
        return $this->settings['pricing'][$model] ?? null;
    }
}