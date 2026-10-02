<?php

declare(strict_types=1);

namespace App\Enum;

enum AnalysisStatus: string
{
    // New lifecycle states (primary)
    case Draft = 'DRAFT';
    case Published = 'PUBLISHED';
    case Processing = 'PROCESSING';
    case Done = 'DONE';
    case Archived = 'ARCHIVED';

    // Legacy states (for backward compatibility - DO NOT use for new code)
    case Created = 'CREATED';
    case Uploaded = 'UPLOADED';
    case Extracted = 'EXTRACTED';
    case NeedsReview = 'NEEDS_REVIEW';
    case Confirmed = 'CONFIRMED';
    case Analyzing = 'ANALYZING';
    case Analyzed = 'ANALYZED';
    case Failed = 'FAILED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function lifecycleValues(): array
    {
        return [
            self::Draft->value,
            self::Published->value,
            self::Processing->value,
            self::Done->value,
            self::Archived->value,
        ];
    }

    public static function legacyValues(): array
    {
        return [
            self::Created->value,
            self::Uploaded->value,
            self::Extracted->value,
            self::NeedsReview->value,
            self::Confirmed->value,
            self::Analyzing->value,
            self::Analyzed->value,
            self::Failed->value,
        ];
    }

    public function isLifecycleState(): bool
    {
        return in_array($this, [self::Draft, self::Published, self::Processing, self::Done, self::Archived], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Done, self::Archived], true);
    }

    public function isLegacy(): bool
    {
        return in_array($this, [
            self::Created, self::Uploaded, self::Extracted, self::NeedsReview,
            self::Confirmed, self::Analyzing, self::Analyzed, self::Failed
        ], true);
    }

    public function toLifecycleState(): self
    {
        return match ($this) {
            self::Created, self::Uploaded, self::Extracted, self::NeedsReview => self::Draft,
            self::Confirmed => self::Published,
            self::Analyzing => self::Processing,
            self::Analyzed => self::Done,
            self::Failed => self::Published,
            default => $this,
        };
    }
}