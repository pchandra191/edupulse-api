<?php

declare(strict_types=1);

namespace App\Service\Analysis\AI;

/**
 * Strips PII from text before sending to AI providers.
 * Removes: names, emails, phone numbers, student IDs, roll numbers, addresses.
 */
readonly class PiiStripper
{
    private const PATTERNS = [
        // Email addresses
        'email' => '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
        // Phone numbers (various formats)
        'phone' => '/(?:\+?\d{1,3}[\s\-]?)?\(?\d{2,4}\)?[\s\-]?\d{3,4}[\s\-]?\d{3,4}/',
        // Student ID / Roll number patterns (common formats)
        'student_id' => '/(?:student[_\s]?id|roll[_\s]?number|admission[_\s]?no)[:\s]*[A-Z0-9]{4,12}/i',
        // Names (capitalized word sequences - heuristic, only at start of line)
        'name' => '/(?:^|\n)([A-Z][a-z]+(?:\s+[A-Z][a-z]+){1,3})\s*[:\-]/m',
        // IP addresses
        'ip_address' => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
        // URLs
        'url' => '/https?:\/\/[^\s]+/',
        // Date of birth patterns
        'dob' => '/(?:date[_\s]?of[_\s]?birth|DOB|born)[:\s]*[\d\/\-]+/i',
    ];

    /**
     * Strip PII from text and return cleaned text + list of stripped items.
     *
     * @return array{cleaned: string, stripped: array<string, mixed>}
     */
    public function strip(string $text): array
    {
        $stripped = [];
        $cleaned = $text;

        foreach (self::PATTERNS as $type => $pattern) {
            $matches = [];
            if (preg_match_all($pattern, $cleaned, $matches, PREG_PATTERN_ORDER)) {
                foreach ($matches[0] as $match) {
                    $stripped[] = [
                        'type' => $type,
                        'original' => $match,
                        'replacement' => '[REDACTED]',
                    ];
                    $cleaned = str_replace($match, '[REDACTED]', $cleaned);
                }
            }
        }

        return ['cleaned' => $cleaned, 'stripped' => $stripped];
    }

    /**
     * Strip PII from an array of strings.
     *
     * @param array<string> $texts
     * @return array{cleaned: array<string>, stripped: array<string, mixed>}
     */
    public function stripMultiple(array $texts): array
    {
        $allStripped = [];
        $cleaned = [];

        foreach ($texts as $text) {
            $result = $this->strip($text);
            $cleaned[] = $result['cleaned'];
            $allStripped = array_merge($allStripped, $result['stripped']);
        }

        return ['cleaned' => $cleaned, 'stripped' => $allStripped];
    }
}