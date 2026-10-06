<?php

declare(strict_types=1);

namespace App\Service\Analysis\Processing;

/**
 * Sanitizes string values for safe inclusion in OpenAI API requests.
 * Removes invalid control characters while preserving valid UTF-8 text,
 * newlines, tabs, and Unicode characters (including Hindi/Devanagari).
 */
class OpenAiSanitizer
{
    /**
     * Sanitize a string for OpenAI API requests.
     *
     * @param string $value The input string to sanitize
     * @return string The sanitized string with invalid control characters removed
     */
    public function sanitize(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // First, validate and normalize UTF-8
        $cleaned = $this->normalizeUtf8($value);
        
        // Remove invalid control characters
        $cleaned = $this->removeInvalidControlChars($cleaned);
        
        return $cleaned;
    }

    /**
     * Sanitize an array of strings recursively.
     *
     * @param array $values The input array to sanitize
     * @return array The sanitized array
     */
    public function sanitizeArray(array $values): array
    {
        $sanitized = [];
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $sanitized[$key] = $this->sanitize($value);
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArray($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }

    /**
     * Validate and normalize UTF-8 encoding.
     */
    private function normalizeUtf8(string $value): string
    {
        // Check if the string is valid UTF-8
        if (!mb_check_encoding($value, 'UTF-8')) {
            // Try to fix encoding issues by removing invalid sequences
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        return $value;
    }

    /**
     * Remove invalid control characters while preserving valid ones.
     * 
     * Preserves:
     * - Newline (\n, \x0A)
     * - Carriage return (\r, \x0D)
     * - Tab (\t, \x09)
     * - Unicode characters (Hindi/Devanagari, mathematical symbols, etc.)
     * 
     * Removes:
     * - Null byte (\x00)
     * - Control chars \x01-\x08, \x0B-\x0C, \x0E-\x1F
     * - DEL (\x7F)
     */
    private function removeInvalidControlChars(string $value): string
    {
        // Pattern to match invalid control characters
        // \x00-\x08: NUL, SOH, STX, ETX, EOT, ENQ, ACK, BEL, BS
        // \x0B-\x0C: VT, FF
        // \x0E-\x1F: SO, SI, DLE, DC1, DC2, DC3, DC4, NAK, SYN, ETB, CAN, EM, SUB, ESC, FS, GS, RS, US
        // \x7F: DEL
        // We use the 'u' modifier for UTF-8 support
        
        $invalidControlChars = '/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/u';
        
        $cleaned = preg_replace($invalidControlChars, '', $value);
        
        return $cleaned;
    }

    /**
     * Get statistics about sanitization for logging.
     *
     * @return array{controlCharsRemoved: int, utf8Valid: bool}
     */
    public function getSanitizationStats(string $original, string $cleaned): array
    {
        $utf8Valid = mb_check_encoding($original, 'UTF-8');
        
        // Count removed control characters
        $originalInvalid = preg_match_all('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/u', $original);
        $cleanedInvalid = preg_match_all('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/u', $cleaned);
        $controlCharsRemoved = max(0, $originalInvalid - $cleanedInvalid);
        
        return [
            'controlCharsRemoved' => $controlCharsRemoved,
            'utf8Valid' => $utf8Valid,
        ];
    }
}